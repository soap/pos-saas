<?php

namespace App\Modules\Billing\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionPayment;
use App\Modules\Payments\DTOs\PaymentRequest;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Providers\InvalidWebhookException;
use App\Modules\Payments\Providers\OmiseProvider;
use App\Support\Errors\ApiException;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * รอบชำระค่าแพ็กเกจ — ร้านจ่ายผ่าน PromptPay ของบัญชี Omise แพลตฟอร์ม (config pos.billing)
 *
 *   billing:cycle (ทุกชั่วโมง)  ออกใบแจ้งหนี้รอบถัดไปล่วงหน้า invoice_lead_days วัน
 *                              และเปลี่ยน trialing/active ที่เลยวันจ่ายถึงเป็น past_due
 *   pay()                      สร้าง charge PromptPay ให้ใบแจ้งหนี้ (ใช้ QR เดิมถ้ายังไม่หมดอายุ)
 *   webhook / refresh()        charge สำเร็จ → ใบแจ้งหนี้ paid + subscription active จนถึงสิ้นรอบ
 *
 * การระงับ (402) ไม่ใช่สถานะใน DB — SubscriptionGate คำนวณจากวันที่จ่ายถึง + grace_days
 * ทุก query ทำงานข้าม tenant (cron/webhook ไม่มี context) จึงกรองด้วย id ที่ระบบรู้เองเท่านั้น
 */
class BillingService
{
    public function __construct(
        private SubscriptionGate $gate,
        private AuditLogger $audit,
        private TenantContext $ctx,
    ) {}

    /** @return array{invoiced: int, past_due: int} */
    public function runCycle(): array
    {
        return $this->ctx->withoutTenancy(fn () => [
            'invoiced' => $this->issueUpcomingInvoices(),
            'past_due' => $this->markPastDue(),
        ]);
    }

    /** ใบแจ้งหนี้ของรอบที่เริ่ม ณ วันที่จ่ายถึง — สร้างครั้งเดียวต่อรอบ (unique subscription_id + billing_period_start) */
    private function issueUpcomingInvoices(): int
    {
        $count = 0;
        $horizon = now()->addDays((int) config('pos.billing.invoice_lead_days'));
        $subscriptions = Subscription::query()->with('plan')
            ->whereIn('status', SubscriptionGate::USABLE_STATUSES)
            ->whereHas('plan', fn ($q) => $q->where('price_satang', '>', 0))
            ->get();

        foreach ($subscriptions as $sub) {
            $periodStart = $sub->paidThrough();
            if ($periodStart === null || $periodStart->greaterThan($horizon)) {
                continue;
            }
            $inserted = DB::table('subscription_payments')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'tenant_id' => $sub->tenant_id,
                'subscription_id' => $sub->id,
                'amount_satang' => $sub->plan->price_satang,
                'currency' => 'THB',
                'status' => 'pending',
                'billing_period_start' => $periodStart,
                'billing_period_end' => $this->periodEnd($periodStart, $sub->plan->billing_interval),
                'plan_price_snapshot_satang' => $sub->plan->price_satang,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $count += $inserted;
        }

        return $count;
    }

    /** เลยวันที่จ่ายถึงแล้ว → past_due; จำวันเดิมไว้ใน current_period_end เพื่อให้ gate นับ grace จากวันนั้น (รวมถึง trial ที่หมด) */
    private function markPastDue(): int
    {
        $count = 0;
        $subscriptions = Subscription::query()->whereIn('status', ['trialing', 'active'])->get();
        foreach ($subscriptions as $sub) {
            $paidThrough = $sub->paidThrough();
            if ($paidThrough === null || $paidThrough->isFuture()) {
                continue;
            }
            $sub->update(['status' => 'past_due', 'current_period_end' => $paidThrough]);
            $this->audit->log('billing.past_due', $sub, ['paid_through' => $paidThrough->toAtomString()], tenantId: $sub->tenant_id);
            $count++;
        }

        return $count;
    }

    /**
     * สร้าง QR PromptPay ให้ใบแจ้งหนี้ — lock ใบแจ้งหนี้ระหว่างเรียก Omise
     * เพื่อไม่ให้กดซ้ำแล้วได้ 2 charge (ref ที่ถูกเขียนทับจะไม่มี webhook มาจับคู่ได้)
     */
    public function pay(SubscriptionPayment $invoice): SubscriptionPayment
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = SubscriptionPayment::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== 'pending') {
                throw ApiException::conflict('invoice_not_payable', 'ใบแจ้งหนี้นี้ชำระแล้วหรือถูกยกเลิก');
            }
            if ($invoice->hasLiveCharge()) {
                return $invoice;
            }

            $provider = $this->provider();
            try {
                $response = $provider->createPayment(new PaymentRequest(
                    paymentId: $invoice->id,
                    attemptNumber: 1,
                    amountSatang: $invoice->amount_satang,
                    currency: $invoice->currency,
                    description: "ค่าแพ็กเกจ {$invoice->billing_period_start->format('Y-m-d')} ถึง {$invoice->billing_period_end->format('Y-m-d')}",
                    expiresAt: now()->addMinutes((int) config('pos.billing.qr_ttl_minutes')),
                ));
            } catch (Throwable $e) {
                Log::warning('billing.charge_failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
                throw new ApiException(502, 'billing_provider_error', 'สร้าง QR ชำระค่าแพ็กเกจไม่สำเร็จ กรุณาลองใหม่');
            }
            $invoice->update([
                'provider_charge_ref' => $response->providerReference,
                'qr_image_url' => $response->qrImageUrl,
                'charge_expires_at' => $response->expiresAt,
            ]);

            return $invoice;
        });
    }

    /** ถามสถานะจาก Omise ตรง ๆ — fallback เมื่อ webhook มาช้า */
    public function refresh(SubscriptionPayment $invoice): SubscriptionPayment
    {
        if ($invoice->status === 'pending' && $invoice->provider_charge_ref !== null) {
            try {
                $status = $this->provider()->getPaymentStatusByReference($invoice->provider_charge_ref);
                $this->applyChargeStatus($invoice->provider_charge_ref, $status->status, 'poll');
            } catch (Throwable $e) {
                Log::warning('billing.refresh_failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
            }
        }

        return $invoice->refresh();
    }

    /**
     * POST /webhooks/billing/omise — verify → dedupe (payment_webhook_events, account platform-billing) → apply
     *
     * @return string processed|duplicate|ignored
     */
    public function handleWebhook(array $headers, string $rawBody): string
    {
        try {
            $hook = $this->provider()->verifyWebhook($headers, $rawBody);
        } catch (InvalidWebhookException $e) {
            Log::warning('billing.webhook_invalid', ['reason' => $e->getMessage()]);
            throw new ApiException(401, 'invalid_signature', 'webhook signature ไม่ถูกต้อง');
        }

        $scope = ['provider_code' => 'omise', 'provider_account_key' => $hook->accountKey, 'external_event_id' => $hook->externalEventId];
        DB::table('payment_webhook_events')->insertOrIgnore($scope + [
            'id' => (string) Str::uuid(),
            'payload' => json_encode($hook->payload),
            'signature_verified_at' => now(),
            'processing_status' => 'received',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::transaction(function () use ($scope, $hook) {
            $event = PaymentWebhookEvent::query()->where($scope)->lockForUpdate()->firstOrFail();
            if ($event->processed_at !== null) {
                return 'duplicate';
            }
            $result = $this->applyChargeStatus($hook->providerReference, $hook->status, 'webhook');
            $processed = in_array($result, ['paid', 'noop'], true);
            $event->update([
                'processed_at' => now(),
                'processing_status' => $processed ? 'processed' : 'ignored',
                'last_error' => $processed ? null : $result,
            ]);

            return $processed ? 'processed' : 'ignored';
        });
    }

    /**
     * ผลของ charge → ใบแจ้งหนี้ + subscription (lock ทั้งคู่)
     * จ่ายช้าจนเลยระยะผ่อนผัน (ถูกระงับไปแล้ว) → เริ่มรอบใหม่ตั้งแต่วันที่จ่าย ไม่เก็บเงินช่วงที่ใช้งานไม่ได้
     *
     * @return string paid|noop|unknown_reference|not_succeeded
     */
    private function applyChargeStatus(string $chargeRef, string $status, string $source): string
    {
        return $this->ctx->withoutTenancy(fn () => DB::transaction(function () use ($chargeRef, $status, $source) {
            $invoice = SubscriptionPayment::query()->where('provider_charge_ref', $chargeRef)->lockForUpdate()->first();
            if (! $invoice) {
                return 'unknown_reference';
            }
            if ($status !== 'succeeded') {
                return 'not_succeeded'; // failed/expired: ใบแจ้งหนี้ยัง pending — กดจ่ายใหม่ได้ QR ใหม่
            }
            if ($invoice->status === 'paid') {
                return 'noop';
            }
            $sub = Subscription::query()->with('plan')->whereKey($invoice->subscription_id)->lockForUpdate()->firstOrFail();

            $graceEndsAt = $invoice->billing_period_start->copy()->addDays((int) config('pos.billing.grace_days'));
            if ($graceEndsAt->isPast()) {
                $invoice->billing_period_start = now();
                $invoice->billing_period_end = $this->periodEnd(now(), $sub->plan->billing_interval);
            }
            $invoice->status = 'paid';
            $invoice->paid_at = now();
            $invoice->save();

            $sub->update(['status' => 'active', 'current_period_end' => $invoice->billing_period_end]);
            $this->audit->log('billing.invoice_paid', $invoice, [
                'amount_satang' => $invoice->amount_satang, 'source' => $source,
                'period_end' => $invoice->billing_period_end->toAtomString(),
            ], tenantId: $invoice->tenant_id);

            return 'paid';
        }));
    }

    /**
     * สรุปสำหรับหน้า "แพ็กเกจ" ของร้าน
     *
     * @return array{subscription: ?Subscription, paid_through: ?Carbon, grace_ends_at: ?Carbon, usable: bool, invoices: Collection<int, SubscriptionPayment>}
     */
    public function summary(string $tenantId): array
    {
        $sub = Subscription::query()->with('plan')->where('tenant_id', $tenantId)->where('status', '<>', 'cancelled')->first();

        return [
            'subscription' => $sub,
            'paid_through' => $sub?->paidThrough(),
            'grace_ends_at' => $sub ? $this->gate->graceEndsAt($sub) : null,
            'usable' => $this->gate->isUsable($sub),
            'invoices' => SubscriptionPayment::query()->where('tenant_id', $tenantId)
                ->latest('billing_period_start')->limit(12)->get(),
        ];
    }

    private function periodEnd(CarbonInterface $start, string $interval): CarbonInterface
    {
        return $interval === 'year' ? $start->copy()->addYearNoOverflow() : $start->copy()->addMonthNoOverflow();
    }

    private function provider(): OmiseProvider
    {
        $config = config('pos.billing.omise');
        if (empty($config['secret_key']) || empty($config['webhook_secret'])) {
            throw new ApiException(503, 'billing_not_configured', 'ระบบรับชำระค่าแพ็กเกจยังไม่ได้ตั้งค่า');
        }

        return new OmiseProvider($config);
    }
}
