<?php

namespace App\Modules\Payments\Services;

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

    /** @return string processed|duplicate|ignored */
    public function handle(string $providerCode, array $headers, string $rawBody): string
    {
        $provider = $this->providers->get($providerCode);

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

        return DB::transaction(function () use ($scope, $hook, $providerCode) {
            $event = PaymentWebhookEvent::query()->where($scope)->lockForUpdate()->firstOrFail();
            if ($event->processed_at !== null) {
                return 'duplicate'; // event ซ้ำ → ไม่สร้างผลทางการเงินซ้ำ
            }

            $attempt = PaymentAttempt::query()->withoutGlobalScopes()
                ->where('provider_code', $providerCode)
                ->where('provider_reference', $hook->providerReference)
                ->first();

            // tenant/store มาจาก attempt ที่ระบบสร้างเอง (ไม่ใช่ payload) แล้วรันใน context นั้น
            $result = $attempt
                ? $this->ctx->runAs(
                    Tenant::query()->findOrFail($attempt->tenant_id),
                    Store::query()->withoutGlobalScopes()->where('tenant_id', $attempt->tenant_id)->findOrFail($attempt->store_id),
                    fn () => $this->payments->applyProviderStatus($attempt, $hook->status, 'webhook'),
                )
                : 'unknown_reference';

            $event->update([
                'processed_at' => now(),
                'processing_status' => $result === 'applied' || $result === 'noop' ? 'processed' : 'ignored',
                'last_error' => in_array($result, ['applied', 'noop'], true) ? null : $result,
            ]);

            return $result === 'applied' || $result === 'noop' ? 'processed' : 'ignored';
        });
    }
}
