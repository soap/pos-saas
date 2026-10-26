<?php

namespace App\Modules\Payments\Providers;

use App\Modules\Payments\Contracts\PaymentProviderInterface;
use App\Modules\Payments\Contracts\SupportsRefunds;
use App\Modules\Payments\DTOs\PaymentRequest;
use App\Modules\Payments\DTOs\PaymentResponse;
use App\Modules\Payments\DTOs\ProviderPaymentStatus;
use App\Modules\Payments\DTOs\RefundResult;
use App\Modules\Payments\DTOs\VerifiedWebhook;
use App\Modules\Payments\Models\PaymentAttempt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Mock/Fake QR provider สำหรับทดสอบ end-to-end (spec §4.4.1)
 *
 * Webhook format:
 *   headers: X-Mock-Timestamp: <unix>, X-Mock-Signature: hex(hmac_sha256("<timestamp>.<rawBody>", secret))
 *   body:    {"id":"evt_...","account":"mock-account","data":{"reference":"MOCK-...","status":"succeeded"}}
 */
class MockQrProvider implements PaymentProviderInterface, SupportsRefunds
{
    public function __construct(private array $config) {}

    public function code(): string
    {
        return 'mock';
    }

    public function createPayment(PaymentRequest $request): PaymentResponse
    {
        $reference = 'MOCK-'.strtoupper(Str::random(20));
        Cache::put($this->key($reference), ['status' => 'pending', 'amount' => $request->amountSatang], now()->addDay());

        return new PaymentResponse(
            providerReference: $reference,
            qrPayload: "MOCKQR|{$reference}|{$request->amountSatang}|{$request->currency}",
            expiresAt: $request->expiresAt,
            safePayload: ['reference' => $reference, 'amount' => $request->amountSatang],
        );
    }

    public function getPaymentStatus(PaymentAttempt $attempt): ProviderPaymentStatus
    {
        $state = Cache::get($this->key($attempt->provider_reference));

        return new ProviderPaymentStatus($state['status'] ?? 'pending', ['reference' => $attempt->provider_reference]);
    }

    public function verifyWebhook(array $headers, string $rawBody): VerifiedWebhook
    {
        $h = array_change_key_case($headers, CASE_LOWER);
        $timestamp = (string) $this->header($h, 'x-mock-timestamp');
        $signature = (string) $this->header($h, 'x-mock-signature');

        if ($timestamp === '' || $signature === '') {
            throw new InvalidWebhookException('missing signature headers');
        }
        if (abs(time() - (int) $timestamp) > (int) ($this->config['replay_window_seconds'] ?? 300)) {
            throw new InvalidWebhookException('timestamp outside replay window');
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, (string) $this->config['webhook_secret']);
        if (! hash_equals($expected, $signature)) {
            throw new InvalidWebhookException('invalid signature');
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload) || empty($payload['id']) || empty($payload['data']['reference']) || empty($payload['data']['status'])) {
            throw new InvalidWebhookException('malformed payload');
        }
        if (($payload['account'] ?? null) !== $this->config['account_key']) {
            throw new InvalidWebhookException('unknown provider account');
        }

        return new VerifiedWebhook(
            externalEventId: (string) $payload['id'],
            accountKey: (string) $payload['account'],
            providerReference: (string) $payload['data']['reference'],
            status: (string) $payload['data']['status'],
            payload: $payload,
        );
    }

    public function refund(string $providerReference, int $amountSatang, string $refundId): RefundResult
    {
        return new RefundResult('succeeded', 'MOCKRF-'.strtoupper(Str::random(16)));
    }

    // ---------- helpers สำหรับจำลองการจ่ายเงิน (dev/test เท่านั้น) ----------

    /** @return array{headers: array<string,string>, body: string} */
    public function simulateWebhook(string $reference, string $status = 'succeeded', ?string $eventId = null): array
    {
        Cache::put($this->key($reference), ['status' => $status], now()->addDay());
        $body = json_encode([
            'id' => $eventId ?? 'evt_'.Str::random(24),
            'account' => $this->config['account_key'],
            'data' => ['reference' => $reference, 'status' => $status],
        ]);
        $ts = (string) time();

        return [
            'headers' => [
                'X-Mock-Timestamp' => $ts,
                'X-Mock-Signature' => hash_hmac('sha256', $ts.'.'.$body, (string) $this->config['webhook_secret']),
                'Content-Type' => 'application/json',
            ],
            'body' => $body,
        ];
    }

    private function header(array $h, string $name): ?string
    {
        $v = $h[$name] ?? null;

        return is_array($v) ? ($v[0] ?? null) : $v;
    }

    private function key(string $reference): string
    {
        return 'mockqr:'.$reference;
    }
}
