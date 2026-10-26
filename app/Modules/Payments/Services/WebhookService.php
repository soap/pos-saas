<?php

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Models\PaymentAccount;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Providers\InvalidWebhookException;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Errors\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * spec §6 ข้อ 6: verify signature → บันทึก event แบบ dedupe → resolve payment → lock → transition
 */
class WebhookService
{
    public function __construct(private PaymentProviderRegistry $providers, private PaymentService $payments, private TenantContext $ctx) {}

    /**
     * @param  ?string  $accountKey  จาก URL /webhooks/payments/{provider}/{account_key} — ใช้เลือก secret ของ tenant ที่ใช้ verify
     * @return string processed|duplicate|ignored
     */
    public function handle(string $providerCode, array $headers, string $rawBody, ?string $accountKey = null): string
    {
        $account = null;
        if ($accountKey !== null) {
            $account = PaymentAccount::query()->withoutGlobalScopes()
                ->where('provider_code', $providerCode)->where('account_key', $accountKey)->where('status', 'active')->first();
            if (! $account) {
                // ไม่บอกว่าไม่มีบัญชี — ตอบเหมือน signature ผิด
                Log::warning('webhook.unknown_account', ['provider' => $providerCode]);
                throw new ApiException(401, 'invalid_signature', 'webhook signature ไม่ถูกต้อง');
            }
        }
        $provider = $account ? $this->providers->forAccount($account) : $this->providers->get($providerCode);

        try {
            $hook = $provider->verifyWebhook($headers, $rawBody);
        } catch (InvalidWebhookException $e) {
            Log::warning('webhook.invalid', ['provider' => $providerCode, 'reason' => $e->getMessage()]);
            throw new ApiException(401, 'invalid_signature', 'webhook signature ไม่ถูกต้อง');
        }

        $scope = [
            'provider_code' => $providerCode,
            'provider_account_key' => $hook->accountKey,
            'external_event_id' => $hook->externalEventId,
        ];
        DB::table('payment_webhook_events')->insertOrIgnore($scope + [
            'id' => (string) Str::uuid(),
            'payload' => json_encode($hook->payload),
            'signature_verified_at' => now(),
            'processing_status' => 'received',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::transaction(function () use ($scope, $hook, $providerCode, $account) {
            $event = PaymentWebhookEvent::query()->where($scope)->lockForUpdate()->firstOrFail();
            if ($event->processed_at !== null) {
                return 'duplicate'; // event ซ้ำ → ไม่สร้างผลทางการเงินซ้ำ
            }

            // attempt ต้องเป็นของบัญชีเดียวกับ secret ที่ verify ผ่าน — บัญชีหนึ่งแตะ payment ของบัญชีอื่นไม่ได้
            $attempt = PaymentAttempt::query()->withoutGlobalScopes()
                ->where('provider_code', $providerCode)
                ->where('provider_reference', $hook->providerReference)
                ->where('payment_account_id', $account?->id)
                ->first();

            // tenant/store มาจาก attempt ที่ระบบสร้างเอง (ไม่ใช่ payload) แล้วรันใน context นั้น
            $result = $attempt
                ? $this->ctx->runAs(
                    Tenant::query()->findOrFail($attempt->tenant_id),
                    Store::query()->withoutGlobalScopes()->where('tenant_id', $attempt->tenant_id)->findOrFail($attempt->store_id),
                    fn () => $this->payments->applyProviderStatus($attempt, $hook->status, 'webhook', $hook->transactionRef, $hook->reason),
                )
                : 'unknown_reference';
            $processed = in_array($result, ['applied', 'noop', 'slip_rejected'], true);

            $event->update([
                'processed_at' => now(),
                'processing_status' => $processed ? 'processed' : 'ignored',
                'last_error' => $processed ? null : $result,
            ]);

            return $processed ? 'processed' : 'ignored';
        });
    }
}
