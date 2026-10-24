<?php

namespace App\Modules\Payments\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Cashier\Services\CashierService;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Services\OrderService;
use App\Modules\Payments\DTOs\PaymentRequest;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Support\Errors\ApiException;
use App\Support\Money\Satang;
use App\Support\Outbox\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Payment state แยกจาก order state (spec §4.3.1)
 *
 *   cash: (สร้าง) ──▶ succeeded            (บันทึก cash_sale ในกะที่เปิดอยู่)
 *   qr:   pending ──createPayment──▶ processing ──webhook/poll──▶ succeeded | failed | cancelled
 *   succeeded ──refund──▶ partially_refunded ──▶ refunded
 */
class PaymentService
{
    private const PROVIDER_TO_PAYMENT = [
        'succeeded' => 'succeeded',
        'failed' => 'failed',
        'expired' => 'cancelled',
    ];

    public function __construct(
        private OrderService $orders,
        private CashierService $cashier,
        private PaymentProviderRegistry $providers,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array{payment: Payment, qr: ?array}
     */
    public function start(Order $order, string $method, ?int $amount, ?int $tendered, string $actorId): array
    {
        if ($method === 'cash') {
            return ['payment' => $this->payCash($order, $amount, $tendered, $actorId), 'qr' => null];
        }

        return $this->startQr($order, $amount, $actorId);
    }

    private function payCash(Order $order, ?int $amount, ?int $tendered, string $actorId): Payment
    {
        return DB::transaction(function () use ($order, $amount, $tendered, $actorId) {
            $order = $this->orders->lock($order);
            $amount = $this->resolveAmount($order, $amount);
            $tendered = Satang::money($tendered ?? $amount);
            if ($tendered->lessThan(Satang::money($amount))) {
                throw ApiException::unprocessable('tendered_insufficient', 'เงินที่รับมาน้อยกว่ายอดชำระ');
            }
            $change = $tendered->subtract(Satang::money($amount));
            $shift = $this->cashier->lockOpenShift($order->store_id);

            $payment = Payment::create([
                'tenant_id' => $order->tenant_id,
                'store_id' => $order->store_id,
                'order_id' => $order->id,
                'cashier_shift_id' => $shift->id,
                'method' => 'cash',
                'amount_satang' => $amount,
                'tendered_satang' => Satang::of($tendered),
                'change_satang' => Satang::of($change),
                'status' => 'succeeded',
                'paid_at' => now(),
                'created_by' => $actorId,
            ]);
            $this->cashier->recordMovement($shift, 'cash_sale', $amount, null, $actorId, 'payment', $payment->id);

            $this->orders->event($order, 'payment.succeeded', null, null, $actorId, ['payment_id' => $payment->id, 'method' => 'cash', 'amount' => $amount]);
            $this->orders->syncStatus($order, $actorId);
            Outbox::record($order->tenant_id, 'payment', $payment->id, 'payment.succeeded', ['order_id' => $order->id, 'method' => 'cash']);

            return $payment;
        });
    }

    /** @return array{payment: Payment, qr: ?array} */
    private function startQr(Order $order, ?int $amount, string $actorId): array
    {
        $provider = $this->providers->defaultQr();

        // 1) บันทึก payment + attempt ใน DB ก่อนเรียก provider (I/O ภายนอกไม่อยู่ใน lock)
        [$payment, $attempt] = DB::transaction(function () use ($order, $amount, $actorId, $provider) {
            $order = $this->orders->lock($order);
            $amount = $this->resolveAmount($order, $amount);
            $payment = Payment::create([
                'tenant_id' => $order->tenant_id,
                'store_id' => $order->store_id,
                'order_id' => $order->id,
                'method' => 'qr',
                'amount_satang' => $amount,
                'status' => 'pending',
                'created_by' => $actorId,
            ]);
            $attempt = PaymentAttempt::create([
                'tenant_id' => $order->tenant_id,
                'store_id' => $order->store_id,
                'payment_id' => $payment->id,
                'attempt_number' => 1,
                'provider_code' => $provider->code(),
                'status' => 'pending',
                'request_payload' => ['amount' => $amount, 'currency' => Satang::CURRENCY],
            ]);

            return [$payment, $attempt];
        });

        // 2) เรียก provider สร้าง QR
        $ttl = (int) config("pos.payments.providers.{$provider->code()}.qr_ttl_minutes", 15);
        try {
            $resp = $provider->createPayment(new PaymentRequest(
                $payment->id, 1, $payment->amount_satang, Satang::CURRENCY, "Order {$order->order_number}", now()->addMinutes($ttl),
            ));
        } catch (Throwable $e) {
            Log::warning('qr.create_failed', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
            DB::transaction(function () use ($payment, $attempt) {
                $attempt->update(['status' => 'failed', 'response_payload' => ['error' => 'provider_error']]);
                $payment->update(['status' => 'failed']);
            });
            throw new ApiException(502, 'provider_error', 'ไม่สามารถสร้าง QR ได้ กรุณาลองใหม่');
        }

        // 3) การสร้าง QR สำเร็จ = processing เท่านั้น (ไม่ใช่ succeeded) — spec §2.1
        DB::transaction(function () use ($payment, $attempt, $resp) {
            $attempt->update([
                'provider_reference' => $resp->providerReference,
                'status' => 'processing',
                'response_payload' => $resp->safePayload,
                'expires_at' => $resp->expiresAt,
            ]);
            $p = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();
            if ($p->status === 'pending') {
                $p->update(['status' => 'processing']);
            }
        });

        return [
            'payment' => $payment->fresh(),
            'qr' => [
                'payload' => $resp->qrPayload,
                'provider' => $attempt->provider_code,
                'reference' => $resp->providerReference,
                'expires_at' => $resp->expiresAt?->format(DATE_ATOM),
            ],
        ];
    }

    /** ยกเลิก QR ที่ยังไม่ได้รับเงิน */
    public function cancel(Payment $payment, string $actorId): Payment
    {
        return DB::transaction(function () use ($payment, $actorId) {
            $order = $this->orders->lock(Order::query()->findOrFail($payment->order_id));
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (! in_array($payment->status, Payment::IN_FLIGHT_STATUSES, true)) {
                throw ApiException::conflict('payment_not_cancellable', "การชำระสถานะ {$payment->status} ยกเลิกไม่ได้");
            }
            $payment->update(['status' => 'cancelled']);
            $payment->attempts()->whereIn('status', ['pending', 'processing'])->update(['status' => 'cancelled', 'updated_at' => now()]);
            $this->orders->event($order, 'payment.cancelled', null, null, $actorId, ['payment_id' => $payment->id]);

            return $payment;
        });
    }

    /** ถามสถานะจาก provider (ใช้เมื่อ webhook มาช้า) */
    public function refresh(Payment $payment): Payment
    {
        if ($payment->method !== 'qr' || ! in_array($payment->status, Payment::IN_FLIGHT_STATUSES, true)) {
            return $payment;
        }
        $attempt = $payment->attempts()->whereNotNull('provider_reference')->latest('attempt_number')->first();
        if (! $attempt) {
            return $payment;
        }
        $status = $this->providers->get($attempt->provider_code)->getPaymentStatus($attempt->provider_reference);
        $this->applyProviderStatus($attempt, $status->status, 'poll');

        return $payment->fresh();
    }

    /**
     * นำสถานะจาก provider มาเปลี่ยน payment ตาม transition ที่อนุญาต
     * ต้อง resolve attempt จาก provider_reference ที่ระบบสร้างเอง (ไม่เชื่อ tenant/order จาก payload)
     */
    public function applyProviderStatus(PaymentAttempt $attempt, string $providerStatus, string $source): string
    {
        $target = self::PROVIDER_TO_PAYMENT[$providerStatus] ?? null;
        if ($target === null) {
            return 'ignored';
        }

        return DB::transaction(function () use ($attempt, $target, $source) {
            $order = $this->orders->lock(Order::query()->withoutGlobalScopes()->findOrFail(
                Payment::query()->withoutGlobalScopes()->whereKey($attempt->payment_id)->value('order_id')
            ));
            $payment = Payment::query()->withoutGlobalScopes()->whereKey($attempt->payment_id)->lockForUpdate()->firstOrFail();

            if ($payment->status === $target || in_array($payment->status, ['partially_refunded', 'refunded'], true)) {
                return 'noop';
            }
            $allowed = in_array($payment->status, Payment::IN_FLIGHT_STATUSES, true)
                // เงินเข้าจริงหลังจากยกเลิก/ล้มเหลว → ต้องรับรู้ยอด (เงินเข้าบัญชีแล้ว)
                || ($target === 'succeeded' && in_array($payment->status, ['cancelled', 'failed'], true));
            if (! $allowed) {
                return 'rejected';
            }

            $from = $payment->status;
            $payment->status = $target;
            if ($target === 'succeeded') {
                $payment->paid_at = now();
            }
            $payment->save();
            PaymentAttempt::query()->withoutGlobalScopes()->whereKey($attempt->id)->update(['status' => $target, 'updated_at' => now()]);

            $this->orders->event($order, 'payment.'.$target, null, null, null, [
                'payment_id' => $payment->id, 'from' => $from, 'source' => $source,
            ]);
            if ($target === 'succeeded') {
                $this->orders->syncStatus($order);
                Outbox::record($order->tenant_id, 'payment', $payment->id, 'payment.succeeded', ['order_id' => $order->id, 'method' => 'qr']);
                if ($from !== 'processing' && $from !== 'pending') {
                    $this->audit->log('payment.late_success', $payment, ['from' => $from], tenantId: $order->tenant_id);
                }
            }

            return 'applied';
        });
    }

    private function resolveAmount(Order $order, ?int $amount): int
    {
        if (! $order->isOpen()) {
            throw ApiException::conflict('order_not_payable', "ออเดอร์สถานะ {$order->status} รับชำระไม่ได้");
        }
        $due = Satang::money($order->total_satang)
            ->subtract($this->orders->netPaid($order))
            ->subtract($this->orders->inFlightAmount($order));
        if (! $due->isPositive()) {
            throw ApiException::conflict('nothing_due', 'ไม่มียอดค้างชำระ (หรือมี QR ที่รอชำระอยู่)');
        }
        $requested = $amount === null ? $due : Satang::money($amount);
        if (! $requested->isPositive() || $requested->greaterThan($due)) {
            throw ApiException::unprocessable('amount_invalid', 'ยอดชำระต้องมากกว่า 0 และไม่เกินยอดค้าง', ['due_satang' => Satang::of($due)]);
        }

        return Satang::of($requested);
    }
}
