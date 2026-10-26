<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Soap\LaravelOmise\Facades\Omise;
use Tests\Support\PosWorld;
use Tests\Support\QrPaymentAccounts;
use Tests\TestCase;

/** spec ภาคผนวก B7 — PromptPay เข้าบัญชีร้าน + ตรวจสลิปด้วย EasySlip (async + webhook) */
class EasySlipPaymentTest extends TestCase
{
    use PosWorld, QrPaymentAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    public function test_qr_is_promptpay_payload_for_store_account_and_amount_without_calling_api(): void
    {
        $this->createEasySlipAccount();
        Http::preventStrayRequests();
        $order = $this->createOrder();

        $qr = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'qr'])->assertCreated()->json('qr');

        $this->assertStringStartsWith('000201010212', $qr['payload']);
        $this->assertStringContainsString('01130066812345678', $qr['payload']); // PromptPay 081-234-5678
        $this->assertStringContainsString('5406100.00', $qr['payload']);        // 100.00 บาท
        $this->assertTrue($qr['requires_slip']);
    }

    public function test_verified_slip_webhook_marks_payment_succeeded(): void
    {
        $account = $this->createEasySlipAccount();
        [$paymentId, $reference] = $this->submitSlipForNewPayment();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.easyslip.com/v2/verify/bank/async'
            && $request['payload'] === 'SLIP-QR-PAYLOAD' && $request['remark'] === $reference && $request['matchAmount'] === 100
            && $request['matchAccount'] === true && $request['checkDuplicate'] === true && $request['callbackUrl'] === $account['webhook_url']);

        $this->sendSlipResult($account['webhook_url'], 'job-1', $reference)->assertOk()->assertJsonPath('result', 'processed');

        $this->assertSame('succeeded', $this->paymentStatus($paymentId));
        $this->assertDatabaseHas('payment_attempts', ['payment_id' => $paymentId, 'provider_transaction_ref' => 'TRANSREF-1']);
    }

    public function test_slip_with_wrong_amount_keeps_payment_open_and_reports_reason(): void
    {
        $account = $this->createEasySlipAccount();
        [$paymentId, $reference] = $this->submitSlipForNewPayment();

        $this->sendSlipResult($account['webhook_url'], 'job-1', $reference, ['isAmountMatched' => false, 'amountInSlip' => 10])->assertOk();

        $this->assertSame('processing', $this->paymentStatus($paymentId));
        $this->api($this->cashierA1, 'GET', "payments/{$paymentId}")->assertOk()
            ->assertJsonPath('data.slip.rejection.reason', 'amount_mismatch');
    }

    public function test_slip_already_used_for_another_payment_is_rejected(): void
    {
        $account = $this->createEasySlipAccount();
        [$firstPaymentId, $firstReference] = $this->submitSlipForNewPayment();
        $this->sendSlipResult($account['webhook_url'], 'job-1', $firstReference)->assertOk();
        [$secondPaymentId, $secondReference] = $this->submitSlipForNewPayment();

        // EasySlip ไม่ได้จับว่าซ้ำ (เช่น ยิงพร้อมกัน) แต่ transRef เดิมถูกใช้ไปแล้ว
        $this->sendSlipResult($account['webhook_url'], 'job-2', $secondReference)->assertOk();

        $this->assertSame('succeeded', $this->paymentStatus($firstPaymentId));
        $this->assertSame('processing', $this->paymentStatus($secondPaymentId));
        $this->api($this->cashierA1, 'GET', "payments/{$secondPaymentId}")->assertJsonPath('data.slip.rejection.reason', 'duplicate_transaction');
    }

    public function test_poll_applies_job_result_when_webhook_is_missing(): void
    {
        $this->createEasySlipAccount();
        [$paymentId, $reference] = $this->submitSlipForNewPayment();
        Http::fake(['https://api.easyslip.com/v2/verify/bank/jobs/job-1' => Http::response(['success' => true, 'data' => [
            'jobId' => 'job-1', 'status' => 'done', 'result' => ['status' => 'success', 'data' => $this->verifiedSlip($reference)],
        ]])]);

        $this->api($this->cashierA1, 'GET', "payments/{$paymentId}?refresh=1")->assertOk()->assertJsonPath('data.status', 'succeeded');
    }

    public function test_webhook_without_signature_returns_401_and_keeps_payment_processing(): void
    {
        $account = $this->createEasySlipAccount();
        [$paymentId, $reference] = $this->submitSlipForNewPayment();

        $this->postWebhook($account['webhook_url'], json_encode(['jobId' => 'job-1', 'status' => 'success', 'data' => $this->verifiedSlip($reference)]), [])
            ->assertStatus(401);

        $this->assertSame('processing', $this->paymentStatus($paymentId));
    }

    public function test_submitting_slip_for_omise_payment_is_rejected_with_422(): void
    {
        $this->createOmiseAccount();
        Omise::fake(['https://api.omise.co/charges*' => Http::response(['object' => 'charge', 'id' => 'chrg_test_1', 'status' => 'pending'])]);
        $order = $this->createOrder();
        $paymentId = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'qr'])->json('data.id');

        $this->api($this->cashierA1, 'POST', "payments/{$paymentId}/slip", ['slip_payload' => 'SLIP-QR-PAYLOAD'])
            ->assertStatus(422)->assertJsonPath('error.code', 'slip_not_supported');
    }

    /** @return array{0: string, 1: string} [payment id, provider reference (= remark)] */
    private function submitSlipForNewPayment(): array
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.easyslip.com/v2/verify/bank/async' => Http::response(['success' => true, 'data' => ['jobId' => 'job-1']], 202)]);
        $order = $this->createOrder();
        $payment = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'qr'])->assertCreated();
        $this->api($this->cashierA1, 'POST', "payments/{$payment->json('data.id')}/slip", ['slip_payload' => 'SLIP-QR-PAYLOAD'])->assertStatus(202);

        return [$payment->json('data.id'), $payment->json('qr.reference')];
    }

    /** @return array<string, mixed> ข้อมูลสลิปที่ผ่านทุกเงื่อนไข (รูปแบบ data ของ EasySlip v2) */
    private function verifiedSlip(string $reference, array $overrides = []): array
    {
        return [
            'remark' => $reference,
            'isDuplicate' => false,
            'matchedAccount' => ['id' => 1],
            'amountInSlip' => 100,
            'isAmountMatched' => true,
            'rawSlip' => ['transRef' => 'TRANSREF-1', 'amount' => ['amount' => 100]],
            ...$overrides,
        ];
    }

    private function sendSlipResult(string $url, string $jobId, string $reference, array $overrides = [])
    {
        $body = json_encode([
            'jobId' => $jobId, 'batchId' => null, 'status' => 'success',
            'data' => $this->verifiedSlip($reference, $overrides), 'timestamp' => now()->toIso8601String(),
        ]);

        return $this->postWebhook($url, $body, ['X-EasySlip-Signature' => 'sha256='.hash_hmac('sha256', $body, $this->easySlipWebhookSecret)]);
    }

    private function paymentStatus(string $paymentId): string
    {
        return DB::table('payments')->where('id', $paymentId)->value('status');
    }
}
