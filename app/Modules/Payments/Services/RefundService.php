<?php

namespace App\Modules\Payments\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Cashier\Services\CashierService;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Services\OrderService;
use App\Modules\Payments\Contracts\SupportsRefunds;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\Refund;
use App\Support\Errors\ApiException;
use App\Support\Money\Satang;
use App\Support\Outbox\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * spec §6 ข้อ 7: lock payment แล้วตรวจ refund ที่สำเร็จ + refund ใหม่ ไม่เกินยอดที่จ่ายสำเร็จ
 *
 *   cash: ทำจบใน transaction เดียว (ไม่มี I/O ภายนอก)
 *   qr:   1) lock → สร้าง refund pending (จองยอด)  2) เรียก provider นอก lock
 *         3) lock → succeeded หรือ failed (refund ที่ล้มเหลวถูกเก็บไว้เป็นหลักฐาน — spec §2.1)
 */
class RefundService
{
    public function __construct(
        private OrderService $orders,
        private CashierService $cashier,
        private PaymentProviderRegistry $providers,
        private AuditLogger $audit,
    ) {}

    public function refund(Payment $payment, int $amount, string $reason, string $actorId): Refund
    {
        if ($payment->method === 'cash') {
            return DB::transaction(function () use ($payment, $amount, $reason, $actorId) {
                [$order, $payment] = $this->lockForRefund($payment, $amount);
                $refund = $this->createPending($payment, $amount, $reason, $actorId);
                $shift = $this->cashier->lockOpenShift($payment->store_id);
                $this->cashier->recordMovement($shift, 'refund', $amount, $reason, $actorId, 'refund', $refund->id);

                return $this->complete($order, $payment, $refund, null, $actorId);
            });
        }

        return $this->refundViaProvider($payment, $amount, $reason, $actorId);
    }

    private function refundViaProvider(Payment $payment, int $amount, string $reason, string $actorId): Refund
    {
        // 1) จองยอดด้วย refund pending ภายใต้ lock — request พร้อมกันจะเห็นยอดที่จองไว้แล้ว
        [$refund, $attempt, $provider] = DB::transaction(function () use ($payment, $amount, $reason, $actorId) {
            [, $payment] = $this->lockForRefund($payment, $amount);
            $attempt = $payment->attempts()->whereNotNull('provider_reference')->latest('attempt_number')->firstOrFail();
            $provider = $this->providers->get($attempt->provider_code);
            if (! $provider instanceof SupportsRefunds) {
                throw ApiException::unprocessable('refund_not_supported', 'provider ไม่รองรับการคืนเงินอัตโนมัติ');
            }

            return [$this->createPending($payment, $amount, $reason, $actorId), $attempt, $provider];
        });

        // 2) เรียก provider นอก transaction (I/O ภายนอกไม่อยู่ใน lock)
        // MVP: mock provider คืนผลทันที; provider จริงที่ async ต้องปล่อยเป็น pending แล้วรอ webhook
        try {
            $result = $provider->refund($attempt->provider_reference, $amount, $refund->id);
        } catch (Throwable $e) {
            Log::warning('refund.provider_error', ['refund_id' => $refund->id, 'error' => $e->getMessage()]);
            $result = null;
        }

        // 3) บันทึกผล
        if ($result?->status !== 'succeeded') {
            $this->fail($refund, $attempt, $actorId, $result === null ? 'provider_error' : 'provider_rejected');
            throw new ApiException(502, 'refund_failed', 'provider ปฏิเสธการคืนเงิน', ['refund_id' => $refund->id]);
        }

        return DB::transaction(function () use ($refund, $result, $actorId) {
            $order = $this->lockOrderOf($refund);
            $payment = Payment::query()->whereKey($refund->payment_id)->lockForUpdate()->firstOrFail();

            return $this->complete($order, $payment, $refund, $result->providerReference, $actorId);
        });
    }

    /** @return array{0: Order, 1: Payment} — lock order ก่อน payment เสมอ (ลำดับเดียวกับ PaymentService) */
    private function lockForRefund(Payment $payment, int $amount): array
    {
        $order = $this->orders->lock(Order::query()->findOrFail($payment->order_id));
        $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

        if (! in_array($payment->status, ['succeeded', 'partially_refunded'], true)) {
            throw ApiException::conflict('payment_not_refundable', "การชำระสถานะ {$payment->status} คืนเงินไม่ได้");
        }
        $reserved = Satang::money((int) Refund::query()->where('payment_id', $payment->id)->where('status', 'pending')->sum('amount_satang'));
        $refundable = Satang::money($payment->amount_satang)
            ->subtract(Satang::money($payment->refunded_satang))
            ->subtract($reserved);
        if (Satang::money($amount)->greaterThan($refundable)) {
            throw ApiException::unprocessable('refund_exceeds_payment', 'ยอดคืนเงินเกินยอดที่ชำระสำเร็จ', [
                'refundable_satang' => Satang::of(Satang::max(Satang::zero(), $refundable)),
            ]);
        }

        return [$order, $payment];
    }

    private function lockOrderOf(Refund $refund): Order
    {
        $orderId = Payment::query()->whereKey($refund->payment_id)->value('order_id');

        return $this->orders->lock(Order::query()->findOrFail($orderId));
    }

    private function createPending(Payment $payment, int $amount, string $reason, string $actorId): Refund
    {
        return Refund::create([
            'tenant_id' => $payment->tenant_id,
            'store_id' => $payment->store_id,
            'payment_id' => $payment->id,
            'amount_satang' => $amount,
            'status' => 'pending',
            'reason' => $reason,
            'created_by' => $actorId,
        ]);
    }

    /** ต้องเรียกภายใน transaction ที่ lock order + payment แล้ว */
    private function complete(Order $order, Payment $payment, Refund $refund, ?string $providerRef, string $actorId): Refund
    {
        $refund->update(['status' => 'succeeded', 'succeeded_at' => now(), 'provider_reference' => $providerRef]);
        $refunded = Satang::money($payment->refunded_satang)->add(Satang::money($refund->amount_satang));
        $payment->refunded_satang = Satang::of($refunded);
        $payment->status = $refunded->greaterThanOrEqual(Satang::money($payment->amount_satang)) ? 'refunded' : 'partially_refunded';
        $payment->save();

        $this->orders->event($order, 'payment.refunded', null, null, $actorId, [
            'payment_id' => $payment->id, 'refund_id' => $refund->id, 'amount' => $refund->amount_satang,
        ]);
        $this->audit->log('payment.refunded', $refund, [
            'payment_id' => $payment->id, 'order_id' => $order->id, 'amount_satang' => $refund->amount_satang, 'reason' => $refund->reason,
        ]);
        Outbox::record($payment->tenant_id, 'payment', $payment->id, 'payment.refunded', ['refund_id' => $refund->id]);
        // คืนยอดที่จ่ายเกิน (เช่น หลังยกเลิก ticket ครัว) แล้วออเดอร์อาจปิดได้
        $this->orders->syncStatus($order, $actorId);

        return $refund;
    }

    private function fail(Refund $refund, PaymentAttempt $attempt, string $actorId, string $error): void
    {
        DB::transaction(function () use ($refund, $attempt, $actorId, $error) {
            $order = $this->lockOrderOf($refund);
            $refund->update(['status' => 'failed']);
            $this->orders->event($order, 'payment.refund_failed', null, null, $actorId, [
                'payment_id' => $refund->payment_id, 'refund_id' => $refund->id, 'amount' => $refund->amount_satang, 'error' => $error,
            ]);
            $this->audit->log('payment.refund_failed', $refund, [
                'payment_id' => $refund->payment_id, 'provider' => $attempt->provider_code,
                'amount_satang' => $refund->amount_satang, 'error' => $error,
            ]);
        });
    }
}
