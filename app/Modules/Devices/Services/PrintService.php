<?php

namespace App\Modules\Devices\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Devices\Models\PrintJob;
use App\Modules\Devices\Models\StoreDevice;
use App\Modules\Kitchen\Models\KitchenTicket;
use App\Modules\Ordering\Models\Order;
use App\Modules\Tenancy\Models\Store;
use App\Support\Errors\ApiException;
use App\Support\Money\Satang;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Print job queue (ภาคผนวก C) — Laravel ไม่เปิด TCP ไปหา printer เอง
 * Local Print Bridge ดึงงานผ่าน API แล้วรายงานผลกลับ
 * - พิมพ์ครั้งแรกต่อเอกสาร: idempotent (สั่งซ้ำได้ job เดิม)
 * - พิมพ์ซ้ำ: ต้องระบุ reprint=true ชัดเจน และ audit
 */
class PrintService
{
    public function __construct(private AuditLogger $audit) {}

    public function request(Store $store, string $documentType, string $sourceId, bool $reprint, ?string $printerId, string $actorId): array
    {
        [$sourceType, $payload] = match ($documentType) {
            'receipt' => ['order', $this->receiptPayload(Order::query()->with(['items.modifiers', 'payments'])->findOrFail($sourceId), $store)],
            'kitchen_ticket' => ['kitchen_ticket', $this->kitchenPayload(KitchenTicket::query()->with(['items', 'order'])->findOrFail($sourceId))],
        };
        if ($printerId && ! StoreDevice::query()->whereKey($printerId)->exists()) {
            throw ApiException::unprocessable('printer_not_found', 'ไม่พบเครื่องพิมพ์ในสาขานี้');
        }

        $baseKey = "{$documentType}:{$sourceId}";
        if (! $reprint) {
            $existing = PrintJob::query()->where('idempotency_key', $baseKey)->first();
            if ($existing) {
                return ['job' => $existing, 'created' => false];
            }
        }

        $job = DB::transaction(function () use ($store, $documentType, $sourceType, $sourceId, $reprint, $printerId, $actorId, $payload, $baseKey) {
            $key = $reprint ? $baseKey.':reprint:'.Str::uuid() : $baseKey;
            DB::table('print_jobs')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'tenant_id' => $store->tenant_id,
                'store_id' => $store->id,
                'document_type' => $documentType,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'printer_device_id' => $printerId,
                'status' => 'pending',
                'is_reprint' => $reprint,
                'idempotency_key' => $key,
                'payload' => json_encode($payload + ['reprint' => $reprint]),
                'requested_by' => $actorId,
                'requested_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $job = PrintJob::query()->where('idempotency_key', $key)->firstOrFail();
            if ($reprint) {
                $this->audit->log('print.reprint', $job, ['document_type' => $documentType, 'source_id' => $sourceId]);
            }

            return $job;
        });

        return ['job' => $job, 'created' => true];
    }

    public function report(PrintJob $job, string $status, ?string $error): PrintJob
    {
        return DB::transaction(function () use ($job, $status, $error) {
            $job = PrintJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            if ($job->status === 'printed') {
                return $job; // idempotent
            }
            $job->attempts++;
            $job->status = $status;
            $job->last_error = $status === 'failed' ? mb_substr((string) $error, 0, 1000) : null;
            if ($status === 'printed') {
                $job->printed_at = now();
            }
            $job->save();

            return $job;
        });
    }

    public function retry(PrintJob $job): PrintJob
    {
        if ($job->status !== 'failed') {
            throw ApiException::conflict('print_job_not_failed', 'retry ได้เฉพาะงานที่ล้มเหลว');
        }
        $job->update(['status' => 'pending']);

        return $job;
    }

    public function receiptPayload(Order $order, Store $store): array
    {
        $paid = $order->payments->whereIn('status', ['succeeded', 'partially_refunded', 'refunded']);

        return [
            'store' => ['name' => $store->name, 'code' => $store->code],
            'order_number' => $order->order_number,
            'business_date' => $order->business_date->format('Y-m-d'),
            'order_type' => $order->order_type,
            'table_label' => $order->table_label,
            'printed_at' => now($store->timezone)->format('Y-m-d H:i'),
            'lines' => $order->items->where('status', 'active')->values()->map(fn ($i) => [
                'name' => $i->product_name_snapshot,
                'quantity' => $i->quantity,
                'modifiers' => $i->modifiers->pluck('name_snapshot')->all(),
                'line_total' => Satang::format($i->line_total_satang),
            ])->all(),
            'subtotal' => Satang::format($order->subtotal_satang),
            'discount' => Satang::format($order->discount_satang),
            'tax' => Satang::format($order->tax_satang),
            // ราคารวม VAT: แสดง "มูลค่าก่อน VAT" + "VAT" โดยยอดรวมไม่เปลี่ยน
            'tax_included' => $store->taxMode() === 'inclusive',
            'amount_before_tax' => Satang::format($order->total_satang - $order->tax_satang),
            'total' => Satang::format($order->total_satang),
            'payments' => $paid->values()->map(fn ($p) => [
                'method' => $p->method,
                'amount' => Satang::format($p->amount_satang),
                'tendered' => $p->tendered_satang !== null ? Satang::format($p->tendered_satang) : null,
                'change' => $p->change_satang !== null ? Satang::format($p->change_satang) : null,
            ])->all(),
            'open_cash_drawer' => $paid->contains('method', 'cash'),
        ];
    }

    private function kitchenPayload(KitchenTicket $t): array
    {
        return [
            'order_number' => $t->order->order_number,
            'order_type' => $t->order->order_type,
            'table_label' => $t->order->table_label,
            'revision' => $t->revision,
            'is_addition' => $t->revision > 1,
            'sent_at' => $t->sent_at?->format(DATE_ATOM),
            'items' => $t->items->map(fn ($i) => [
                'name' => $i->name_snapshot, 'quantity' => $i->quantity,
                'modifiers' => $i->modifiers_snapshot, 'notes' => $i->notes,
            ])->all(),
        ];
    }
}
