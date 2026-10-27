<?php

namespace App\Modules\Payments\Providers;

use App\Modules\Payments\Contracts\PaymentProviderInterface;
use App\Modules\Payments\DTOs\PaymentRequest;
use App\Modules\Payments\DTOs\PaymentResponse;
use App\Modules\Payments\DTOs\ProviderPaymentStatus;
use App\Modules\Payments\DTOs\VerifiedWebhook;
use App\Modules\Payments\Models\PaymentAttempt;
use Illuminate\Support\Carbon;
use Soap\LaravelOmise\Omise;
use Soap\OmiseWebhooks\Exceptions\InvalidOmiseSignature;
use Soap\OmiseWebhooks\OmiseSignatureValidator;

/**
 * Omise PromptPay (https://docs.omise.co/promptpay) ผ่าน soap/laravel-omise ด้วยคีย์ของบัญชี tenant (Omise::withKeys)
 *
 * - createPayment: charge source[type]=promptpay → QR เป็นรูปที่ source.scannable_code.image.download_uri
 * - webhook: ตรวจ Omise-Signature ด้วย OmiseSignatureValidator ของ soap/laravel-omise-webhooks (standalone, ไม่ใช้ route/pipeline
 *   ของ package) กับ webhook secret ของบัญชี — รับผ่าน WebhookService เพื่อ dedupe ต่อ account เหมือน provider อื่น
 * - ไม่ implement SupportsRefunds: "PromptPay charges cannot be voided or refunded through Omise" → คืนเงินแบบ manual_transfer
 * - ยอดขั้นต่ำ 20.00 บาท (config min_amount_satang)
 */
class OmiseProvider implements PaymentProviderInterface
{
    private const STATUS_MAP = [
        'pending' => 'pending',
        'successful' => 'succeeded',
        'failed' => 'failed',
        'expired' => 'expired',
    ];

    /** @param array{public_key: string, secret_key: string, webhook_secret: string, account_key: string, replay_window_seconds?: int} $config */
    public function __construct(private array $config) {}

    public function code(): string
    {
        return 'omise';
    }

    public function createPayment(PaymentRequest $request): PaymentResponse
    {
        $charge = $this->omise()->charge()->create([
            'amount' => $request->amountSatang,
            'currency' => strtolower($request->currency),
            'source' => ['type' => 'promptpay'],
            'expires_at' => Carbon::instance($request->expiresAt)->utc()->toIso8601ZuluString(),
            'description' => $request->description,
            'metadata' => ['payment_id' => $request->paymentId, 'attempt_number' => $request->attemptNumber],
        ])->throw();

        return new PaymentResponse(
            providerReference: $charge->id,
            qrPayload: null,
            expiresAt: $charge->expires_at ? Carbon::parse($charge->expires_at) : $request->expiresAt,
            safePayload: ['charge_id' => $charge->id, 'status' => $charge->status],
            qrImageUrl: data_get($charge->source, 'scannable_code.image.download_uri'),
        );
    }

    public function getPaymentStatus(PaymentAttempt $attempt): ProviderPaymentStatus
    {
        return $this->getPaymentStatusByReference($attempt->provider_reference);
    }

    /** สถานะ charge ตาม id — ใช้ทั้ง payment ของร้านและใบแจ้งหนี้ค่าแพ็กเกจ (ซึ่งไม่มี PaymentAttempt) */
    public function getPaymentStatusByReference(string $chargeId): ProviderPaymentStatus
    {
        $charge = $this->omise()->charge()->find($chargeId)->throw();

        return new ProviderPaymentStatus(
            self::STATUS_MAP[$charge->status] ?? 'pending',
            ['charge_id' => $charge->id, 'status' => $charge->status],
            transactionRef: $charge->transaction,
        );
    }

    public function verifyWebhook(array $headers, string $rawBody): VerifiedWebhook
    {
        $h = array_change_key_case($headers, CASE_LOWER);
        try {
            OmiseSignatureValidator::assertValid(
                $rawBody,
                $this->header($h, 'omise-signature'),
                $this->header($h, 'omise-signature-timestamp'),
                (string) $this->config['webhook_secret'],
                (int) ($this->config['replay_window_seconds'] ?? 300),
            );
        } catch (InvalidOmiseSignature $e) {
            throw new InvalidWebhookException($e->getMessage());
        }

        $event = json_decode($rawBody, true);
        $charge = is_array($event) ? ($event['data'] ?? null) : null;
        if (empty($event['id']) || ! is_array($charge) || ($charge['object'] ?? null) !== 'charge' || empty($charge['id'])) {
            throw new InvalidWebhookException('malformed payload');
        }

        return new VerifiedWebhook(
            externalEventId: (string) $event['id'],
            accountKey: (string) $this->config['account_key'],
            providerReference: (string) $charge['id'],
            // event อื่นที่ไม่ใช่ charge (เช่น transfer) ไม่ map เป็นสถานะ payment
            status: self::STATUS_MAP[$charge['status'] ?? ''] ?? 'ignored',
            payload: ['id' => $event['id'], 'key' => $event['key'] ?? null, 'charge_id' => $charge['id'], 'status' => $charge['status'] ?? null],
            transactionRef: $charge['transaction'] ?? null,
        );
    }

    private function omise(): Omise
    {
        return app(Omise::class)->withKeys((string) $this->config['public_key'], (string) $this->config['secret_key']);
    }

    private function header(array $h, string $name): ?string
    {
        $v = $h[$name] ?? null;

        return is_array($v) ? ($v[0] ?? null) : $v;
    }
}
