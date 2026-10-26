<?php

namespace App\Modules\Payments\Providers;

use App\Modules\Payments\Contracts\PaymentProviderInterface;
use App\Modules\Payments\Contracts\SlipRejectedException;
use App\Modules\Payments\Contracts\VerifiesSlips;
use App\Modules\Payments\DTOs\PaymentRequest;
use App\Modules\Payments\DTOs\PaymentResponse;
use App\Modules\Payments\DTOs\ProviderPaymentStatus;
use App\Modules\Payments\DTOs\VerifiedWebhook;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Services\PromptPayPayload;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * PromptPay เข้าบัญชีร้านโดยตรง + ตรวจสลิปด้วย EasySlip API v2 แบบ async (https://document.easyslip.com/en/v2/)
 *
 *   createPayment: สร้าง PromptPay QR พร้อมยอดเองจาก settings.promptpay_id (ไม่เรียก API)
 *   submitSlip:    POST /verify/bank/async {payload, callbackUrl, remark=provider_reference, matchAccount, matchAmount, checkDuplicate}
 *   webhook:       X-EasySlip-Signature: sha256=hex(hmac_sha256(rawBody, webhook_secret)); dedupe ด้วย jobId
 *   poll:          GET /verify/bank/jobs/{jobId} (fallback เมื่อ webhook ไม่มา)
 *
 * สลิปผ่านเมื่อ: ไม่ซ้ำ, ผู้รับตรงกับบัญชีที่ลงทะเบียนใน dashboard EasySlip ของ tenant และยอดตรง
 * ไม่ผ่าน → status "rejected" (payment ยังรอสลิปใหม่ได้) — ไม่มี refund API → คืนเงินแบบ manual_transfer
 */
class EasySlipProvider implements PaymentProviderInterface, VerifiesSlips
{
    /** @param array{api_key: string, webhook_secret: string, promptpay_id: string, account_key: string, webhook_url: string, api_base?: string} $config */
    public function __construct(private array $config) {}

    public function code(): string
    {
        return 'easyslip';
    }

    public function createPayment(PaymentRequest $request): PaymentResponse
    {
        $reference = 'ES'.strtoupper(Str::random(22));

        return new PaymentResponse(
            providerReference: $reference,
            qrPayload: PromptPayPayload::build((string) $this->config['promptpay_id'], $request->amountSatang),
            expiresAt: $request->expiresAt,
            safePayload: ['reference' => $reference, 'amount' => $request->amountSatang],
        );
    }

    public function submitSlip(PaymentAttempt $attempt, string $slipPayload, int $amountSatang): array
    {
        $response = $this->api()->post('/verify/bank/async', [
            'payload' => $slipPayload,
            'callbackUrl' => $this->config['webhook_url'],
            'remark' => $attempt->provider_reference,
            'matchAccount' => true,
            'matchAmount' => $amountSatang / 100,
            'checkDuplicate' => true,
        ]);
        if ($response->clientError() && $response->status() !== 429) {
            throw new SlipRejectedException((string) $response->json('error.code', 'invalid_slip'));
        }
        $response->throw();

        return ['slip_job_id' => (string) $response->json('data.jobId')];
    }

    public function getPaymentStatus(PaymentAttempt $attempt): ProviderPaymentStatus
    {
        $jobId = $attempt->response_payload['slip_job_id'] ?? null;
        if (! $jobId) {
            return new ProviderPaymentStatus('pending');
        }
        $job = $this->api()->get('/verify/bank/jobs/'.rawurlencode($jobId))->throw()->json('data');

        return match ($job['status'] ?? null) {
            'done' => $this->interpret($job['result']['status'] ?? null, $job['result']['data'] ?? null, null),
            'failed' => new ProviderPaymentStatus('rejected', ['job_id' => $jobId], reason: (string) ($job['error']['code'] ?? 'verification_failed')),
            default => new ProviderPaymentStatus('pending', ['job_id' => $jobId]),
        };
    }

    public function verifyWebhook(array $headers, string $rawBody): VerifiedWebhook
    {
        $h = array_change_key_case($headers, CASE_LOWER);
        $header = $h['x-easyslip-signature'] ?? null;
        $signature = (string) (is_array($header) ? ($header[0] ?? '') : $header);
        if (! str_starts_with($signature, 'sha256=')) {
            throw new InvalidWebhookException('missing signature header');
        }
        $expected = hash_hmac('sha256', $rawBody, (string) $this->config['webhook_secret']);
        if (! hash_equals($expected, substr($signature, 7))) {
            throw new InvalidWebhookException('invalid signature');
        }

        $body = json_decode($rawBody, true);
        if (! is_array($body) || empty($body['jobId']) || empty($body['status'])) {
            throw new InvalidWebhookException('malformed payload');
        }
        $data = is_array($body['data'] ?? null) ? $body['data'] : null;
        $result = $this->interpret($body['status'], $data, $body['error']['code'] ?? null);

        return new VerifiedWebhook(
            externalEventId: (string) $body['jobId'],
            accountKey: (string) $this->config['account_key'],
            providerReference: (string) ($data['remark'] ?? ''),
            status: $result->status,
            payload: ['jobId' => $body['jobId'], 'status' => $body['status']] + $result->safePayload,
            transactionRef: $result->transactionRef,
            reason: $result->reason,
        );
    }

    /** แปลงผลตรวจสลิป (รูปแบบเดียวกันทั้ง webhook และ poll) เป็นสถานะ payment */
    private function interpret(?string $status, ?array $data, ?string $errorCode): ProviderPaymentStatus
    {
        if ($status !== 'success' || $data === null) {
            return new ProviderPaymentStatus('rejected', reason: $status === 'not_found' ? 'slip_not_found' : ($errorCode ?? 'verification_failed'));
        }
        $transRef = data_get($data, 'rawSlip.transRef');
        $summary = ['trans_ref' => $transRef, 'amount_in_slip' => $data['amountInSlip'] ?? data_get($data, 'rawSlip.amount.amount')];

        $reason = match (true) {
            ($data['isDuplicate'] ?? false) === true => 'duplicate_slip',
            empty($data['matchedAccount']) => 'receiver_mismatch',
            ($data['isAmountMatched'] ?? false) !== true => 'amount_mismatch',
            ! is_string($transRef) || $transRef === '' => 'missing_transaction_ref',
            default => null,
        };

        return $reason === null
            ? new ProviderPaymentStatus('succeeded', $summary, transactionRef: $transRef)
            : new ProviderPaymentStatus('rejected', $summary, reason: $reason);
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl($this->config['api_base'] ?? 'https://api.easyslip.com/v2')
            ->withToken((string) $this->config['api_key'])
            ->acceptJson()
            ->asJson()
            ->timeout(15);
    }
}
