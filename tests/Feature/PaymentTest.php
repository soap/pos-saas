<?php

namespace Tests\Feature;

use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Providers\MockQrProvider;
use App\Modules\Payments\Services\PaymentProviderRegistry;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FailingRefundQrProvider;
use Tests\Support\PosWorld;
use Tests\TestCase;

/** spec §10 Payment, Refund + §4.4.1 */
class PaymentTest extends TestCase
{
    use PosWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    private function webhook(array $hook, ?string $tamperBody = null)
    {
        $server = [];
        foreach ($hook['headers'] as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $server['CONTENT_TYPE'] = 'application/json';

        return $this->call('POST', '/api/v1/webhooks/payments/mock', [], [], [], $server, $tamperBody ?? $hook['body']);
    }

    private function qrProvider()
    {
        return app(PaymentProviderRegistry::class)->get('mock');
    }

    public function test_cash_payment_requires_open_shift_and_records_cash_sale(): void
    {
        $order = $this->createOrder(); // 10000
        $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'cash'])
            ->assertStatus(409)->assertJsonPath('error.code', 'shift_not_open');

        $shift = $this->openShift($this->cashierA1, 50000);
        $pay = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'cash', 'tendered_satang' => 20000])->assertCreated();
        $pay->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.amount_satang', 10000)->assertJsonPath('data.change_satang', 10000);

        $this->assertDatabaseHas('cash_movements', ['shift_id' => $shift['id'], 'movement_type' => 'cash_sale', 'amount_satang' => 10000]);
        $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'cash'])
            ->assertStatus(409)->assertJsonPath('error.code', 'nothing_due');
    }

    public function test_order_completes_when_paid_and_served(): void
    {
        $this->openShift($this->cashierA1);
        $order = $this->createOrder();
        $ticket = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/send-to-kitchen")->json('data');
        $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'cash'])->assertCreated();
        $this->api($this->ownerA, 'GET', "orders/{$order['id']}")->assertJsonPath('data.status', 'placed');

        $this->api($this->kitchenA1, 'POST', "kitchen/tickets/{$ticket['id']}/ready")->assertOk();
        $this->api($this->cashierA1, 'POST', "kitchen/tickets/{$ticket['id']}/served")->assertOk();
        $this->api($this->ownerA, 'GET', "orders/{$order['id']}")->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.balance_due_satang', 0);
    }

    public function test_client_cannot_overpay_or_fake_amount(): void
    {
        $this->openShift($this->cashierA1);
        $order = $this->createOrder();
        $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'cash', 'amount_satang' => 999999])
            ->assertStatus(422)->assertJsonPath('error.code', 'amount_invalid');
        // ชำระบางส่วนได้
        $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'cash', 'amount_satang' => 4000])->assertCreated();
        $this->api($this->ownerA, 'GET', "orders/{$order['id']}")->assertJsonPath('data.balance_due_satang', 6000);
    }

    public function test_qr_creation_is_only_processing_until_verified_webhook(): void
    {
        $order = $this->createOrder();
        $res = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'qr'])->assertCreated();
        $res->assertJsonPath('data.status', 'processing');
        $this->assertStringStartsWith('MOCKQR|', $res->json('qr.payload'));
        $ref = $res->json('qr.reference');
        $paymentId = $res->json('data.id');

        // QR ค้างอยู่ → ห้ามสร้างการชำระซ้อน
        $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'qr'])
            ->assertStatus(409)->assertJsonPath('error.code', 'nothing_due');

        // webhook ที่ signature ไม่ถูกต้อง → 401 และสถานะไม่เปลี่ยน
        $hook = $this->qrProvider()->simulateWebhook($ref, 'succeeded');
        $tampered = str_replace('succeeded', 'succeeded ', $hook['body']);
        $this->webhook($hook, $tampered)->assertStatus(401)->assertJsonPath('error.code', 'invalid_signature');
        $this->assertSame('processing', $this->inTenant($this->tenantA, fn () => Payment::query()->find($paymentId)->status));

        // webhook ถูกต้อง → succeeded
        $this->webhook($hook)->assertOk()->assertJsonPath('result', 'processed');
        $this->assertSame('succeeded', $this->inTenant($this->tenantA, fn () => Payment::query()->find($paymentId)->status));

        // webhook ซ้ำ → ไม่เกิดผลซ้ำ
        $this->webhook($hook)->assertOk()->assertJsonPath('result', 'duplicate');
        $this->assertSame(1, \DB::table('payment_webhook_events')->count());
        $this->assertSame(1, \DB::table('outbox_events')->where('aggregate_id', $paymentId)->where('event_type', 'payment.succeeded')->count());
    }

    public function test_webhook_from_unknown_account_is_rejected(): void
    {
        $order = $this->createOrder();
        $ref = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'qr'])->json('qr.reference');
        $body = json_encode(['id' => 'evt_x', 'account' => 'other-account', 'data' => ['reference' => $ref, 'status' => 'succeeded']]);
        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts.'.'.$body, config('pos.payments.providers.mock.webhook_secret'));

        $this->webhook(['headers' => ['X-Mock-Timestamp' => $ts, 'X-Mock-Signature' => $sig], 'body' => $body])->assertStatus(401);
        $this->assertSame('processing', $this->inTenant($this->tenantA, fn () => Payment::query()->first()->status));
    }

    public function test_webhook_outside_replay_window_is_rejected(): void
    {
        $body = json_encode(['id' => 'evt_old', 'account' => 'mock-account', 'data' => ['reference' => 'MOCK-X', 'status' => 'succeeded']]);
        $ts = (string) (time() - 3600);
        $sig = hash_hmac('sha256', $ts.'.'.$body, config('pos.payments.providers.mock.webhook_secret'));
        $this->webhook(['headers' => ['X-Mock-Timestamp' => $ts, 'X-Mock-Signature' => $sig], 'body' => $body])->assertStatus(401);
    }

    public function test_status_poll_fallback_and_cancel(): void
    {
        $order = $this->createOrder();
        $res = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'qr']);
        $paymentId = $res->json('data.id');

        $this->api($this->cashierA1, 'GET', "payments/{$paymentId}?refresh=1")->assertJsonPath('data.status', 'processing');
        $this->api($this->cashierA1, 'POST', "payments/{$paymentId}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        // สร้าง QR ใหม่ได้หลังยกเลิก แล้ว provider ยืนยันผ่าน poll
        $res2 = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'qr'])->assertCreated();
        Cache::put('mockqr:'.$res2->json('qr.reference'), ['status' => 'succeeded'], 60);
        $this->api($this->cashierA1, 'GET', "payments/{$res2->json('data.id')}?refresh=1")->assertJsonPath('data.status', 'succeeded');
    }

    public function test_refund_cannot_exceed_paid_amount(): void
    {
        $this->openShift($this->cashierA1);
        $order = $this->createOrder();
        $paymentId = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'cash'])->json('data.id');

        $this->api($this->cashierA1, 'POST', "payments/{$paymentId}/refunds", ['amount_satang' => 1000, 'reason' => 'ของหมด'])->assertForbidden();

        $this->api($this->ownerA, 'POST', "payments/{$paymentId}/refunds", ['amount_satang' => 6000, 'reason' => 'ของหมด'])
            ->assertCreated()->assertJsonPath('payment.status', 'partially_refunded');
        $this->api($this->ownerA, 'POST', "payments/{$paymentId}/refunds", ['amount_satang' => 5000, 'reason' => 'ของหมด'])
            ->assertStatus(422)->assertJsonPath('error.code', 'refund_exceeds_payment');
        $this->api($this->ownerA, 'POST', "payments/{$paymentId}/refunds", ['amount_satang' => 4000, 'reason' => 'ของหมด'])
            ->assertCreated()->assertJsonPath('payment.status', 'refunded');

        $this->assertSame(10000, (int) \DB::table('refunds')->where('payment_id', $paymentId)->where('status', 'succeeded')->sum('amount_satang'));
        $this->assertSame(2, \DB::table('cash_movements')->where('movement_type', 'refund')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.refunded']);
    }

    public function test_qr_refund_goes_through_provider(): void
    {
        $order = $this->createOrder();
        $res = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'qr']);
        $this->webhook($this->qrProvider()->simulateWebhook($res->json('qr.reference')))->assertOk();

        $this->api($this->ownerA, 'POST', "payments/{$res->json('data.id')}/refunds", ['amount_satang' => 10000, 'reason' => 'ลูกค้าขอคืน'])
            ->assertCreated()->assertJsonPath('payment.status', 'refunded');
        $this->assertStringStartsWith('MOCKRF-', \DB::table('refunds')->value('provider_reference'));
    }

    public function test_failed_qr_refund_is_kept_and_releases_reserved_amount(): void
    {
        $order = $this->createOrder();
        $res = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'qr']);
        $this->webhook($this->qrProvider()->simulateWebhook($res->json('qr.reference')))->assertOk();
        $paymentId = $res->json('data.id');

        config(['pos.payments.providers.mock.class' => FailingRefundQrProvider::class]);
        app()->forgetInstance(PaymentProviderRegistry::class);
        $this->api($this->ownerA, 'POST', "payments/{$paymentId}/refunds", ['amount_satang' => 10000, 'reason' => 'ลูกค้าขอคืน'])
            ->assertStatus(502)->assertJsonPath('error.code', 'refund_failed');

        // refund ที่ล้มเหลวต้องยังอยู่เป็นหลักฐาน และไม่กระทบยอด payment
        $this->assertDatabaseHas('refunds', ['payment_id' => $paymentId, 'status' => 'failed', 'amount_satang' => 10000]);
        $this->assertDatabaseHas('payments', ['id' => $paymentId, 'status' => 'succeeded', 'refunded_satang' => 0]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.refund_failed']);
        $this->assertDatabaseHas('order_events', ['order_id' => $order['id'], 'event_type' => 'payment.refund_failed']);

        // ยอดที่จองไว้ถูกปล่อยแล้ว → คืนเต็มจำนวนได้เมื่อ provider กลับมาปกติ
        config(['pos.payments.providers.mock.class' => MockQrProvider::class]);
        app()->forgetInstance(PaymentProviderRegistry::class);
        $this->api($this->ownerA, 'POST', "payments/{$paymentId}/refunds", ['amount_satang' => 10000, 'reason' => 'ลูกค้าขอคืน'])
            ->assertCreated()->assertJsonPath('payment.status', 'refunded');
    }

    public function test_pending_refund_reserves_amount(): void
    {
        $order = $this->createOrder();
        $res = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'qr']);
        $this->webhook($this->qrProvider()->simulateWebhook($res->json('qr.reference')))->assertOk();
        $payment = $this->inTenant($this->tenantA, fn () => Payment::query()->findOrFail($res->json('data.id')));

        // จำลอง refund ที่กำลังรอ provider อยู่ (ขั้นที่ 2 ของ RefundService)
        Refund::create([
            'tenant_id' => $payment->tenant_id, 'store_id' => $payment->store_id, 'payment_id' => $payment->id,
            'amount_satang' => 7000, 'status' => 'pending', 'reason' => 'in flight',
        ]);
        $this->api($this->ownerA, 'POST', "payments/{$payment->id}/refunds", ['amount_satang' => 5000, 'reason' => 'ซ้ำ'])
            ->assertStatus(422)->assertJsonPath('error.code', 'refund_exceeds_payment')
            ->assertJsonPath('error.details.refundable_satang', 3000);
    }

    public function test_attempt_payload_has_no_secret(): void
    {
        $order = $this->createOrder();
        $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'qr']);
        $attempt = PaymentAttempt::query()->first();
        $this->assertStringNotContainsString(config('pos.payments.providers.mock.webhook_secret'), json_encode($attempt->toArray()));
    }
}
