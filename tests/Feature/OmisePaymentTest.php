<?php

namespace Tests\Feature;

use App\Modules\Payments\Models\Payment;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Soap\LaravelOmise\Facades\Omise;
use Tests\Support\PosWorld;
use Tests\Support\QrPaymentAccounts;
use Tests\TestCase;

/** spec ภาคผนวก B7 — Omise PromptPay ผ่านบัญชีของ tenant */
class OmisePaymentTest extends TestCase
{
    use PosWorld, QrPaymentAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    public function test_qr_charge_is_processing_until_signed_webhook_marks_it_succeeded(): void
    {
        $account = $this->createOmiseAccount();
        [$paymentId, $qr] = $this->startOmiseQr();

        $this->assertSame('https://api.omise.co/charges/chrg_test_1/documents/docu_1/downloads/qr', $qr['image_url']);
        $this->assertSame('processing', $this->paymentStatus($paymentId));
        Http::assertSent(fn (Request $request): bool => rtrim($request->url(), '/') === 'https://api.omise.co/charges'
            && $request['amount'] === '10000' && $request['source'] === ['type' => 'promptpay']
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('skey_test_5xyz:')));

        $this->sendChargeEvent($account['webhook_url'], 'chrg_test_1', 'successful')->assertOk()->assertJsonPath('result', 'processed');

        $this->assertSame('succeeded', $this->paymentStatus($paymentId));
        $this->assertDatabaseHas('payment_attempts', ['payment_id' => $paymentId, 'provider_transaction_ref' => 'trxn_test_1']);
    }

    public function test_webhook_with_wrong_signature_returns_401_and_keeps_payment_processing(): void
    {
        $account = $this->createOmiseAccount();
        [$paymentId] = $this->startOmiseQr();

        $this->sendChargeEvent($account['webhook_url'], 'chrg_test_1', 'successful', secret: base64_encode('wrong'))
            ->assertStatus(401)->assertJsonPath('error.code', 'invalid_signature');

        $this->assertSame('processing', $this->paymentStatus($paymentId));
    }

    public function test_webhook_signed_by_another_tenants_account_cannot_settle_payment(): void
    {
        $this->createOmiseAccount();
        $accountOfB = $this->createOmiseAccount($this->ownerB, $this->storeB1);
        [$paymentId] = $this->startOmiseQr();

        // tenant B ส่ง event ถูก signature ของตัวเอง แต่อ้าง charge ของ tenant A
        $this->sendChargeEvent($accountOfB['webhook_url'], 'chrg_test_1', 'successful')->assertOk()->assertJsonPath('result', 'ignored');

        $this->assertSame('processing', $this->paymentStatus($paymentId));
    }

    public function test_qr_below_omise_minimum_of_20_baht_is_rejected_with_422(): void
    {
        $this->createOmiseAccount();
        Http::preventStrayRequests();
        $order = $this->createOrder(items: [['product_id' => $this->waterA->id, 'quantity' => 1]]);

        $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'qr'])
            ->assertStatus(422)->assertJsonPath('error.code', 'amount_below_provider_minimum')
            ->assertJsonPath('error.details.min_amount_satang', 2000);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_refund_requires_manual_transfer_because_promptpay_cannot_be_refunded_via_omise(): void
    {
        $account = $this->createOmiseAccount();
        [$paymentId] = $this->startOmiseQr();
        $this->sendChargeEvent($account['webhook_url'], 'chrg_test_1', 'successful')->assertOk();

        $this->api($this->ownerA, 'POST', "payments/{$paymentId}/refunds", ['amount_satang' => 3000, 'reason' => 'ของหมด'])
            ->assertStatus(422)->assertJsonPath('error.code', 'manual_refund_required');

        $this->api($this->ownerA, 'POST', "payments/{$paymentId}/refunds", [
            'amount_satang' => 3000, 'reason' => 'ของหมด', 'refund_method' => 'manual_transfer', 'transfer_reference' => 'KBANK-123',
        ])->assertCreated()->assertJsonPath('data.method', 'manual_transfer')->assertJsonPath('payment.status', 'partially_refunded');

        $this->assertDatabaseHas('refunds', ['payment_id' => $paymentId, 'method' => 'manual_transfer', 'status' => 'succeeded', 'provider_reference' => 'KBANK-123']);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    /** @return array{0: string, 1: array<string, mixed>} [payment id, qr] */
    private function startOmiseQr(): array
    {
        Http::preventStrayRequests();
        Omise::fake(['https://api.omise.co/charges*' => Http::response([
            'object' => 'charge', 'id' => 'chrg_test_1', 'status' => 'pending', 'expires_at' => now()->addMinutes(15)->toIso8601ZuluString(),
            'source' => ['type' => 'promptpay', 'scannable_code' => ['image' => ['download_uri' => 'https://api.omise.co/charges/chrg_test_1/documents/docu_1/downloads/qr']]],
        ])]);
        $order = $this->createOrder();
        $response = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'qr'])->assertCreated();

        return [$response->json('data.id'), $response->json('qr')];
    }

    private function sendChargeEvent(string $url, string $chargeId, string $status, ?string $secret = null)
    {
        $body = json_encode([
            'object' => 'event', 'id' => 'evnt_test_'.$chargeId, 'key' => 'charge.complete',
            'data' => ['object' => 'charge', 'id' => $chargeId, 'status' => $status, 'transaction' => 'trxn_test_1'],
        ]);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, base64_decode($secret ?? $this->omiseWebhookSecret));

        return $this->postWebhook($url, $body, ['Omise-Signature' => $signature, 'Omise-Signature-Timestamp' => $timestamp]);
    }

    private function paymentStatus(string $paymentId): string
    {
        return DB::table('payments')->where('id', $paymentId)->value('status');
    }
}
