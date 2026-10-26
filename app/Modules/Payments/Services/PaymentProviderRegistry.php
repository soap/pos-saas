<?php

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Contracts\PaymentProviderInterface;
use App\Modules\Payments\Models\PaymentAccount;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Tenancy\Models\Store;
use App\Support\Errors\ApiException;

/**
 * สร้าง provider จาก config ระดับระบบ (pos.payments.providers.{code}) รวมกับ credentials/settings ของ PaymentAccount
 * provider ที่ไม่มี account (เช่น mock) ใช้ config อย่างเดียว
 */
class PaymentProviderRegistry
{
    /** @var array<string,PaymentProviderInterface> */
    private array $resolved = [];

    public function get(string $code): PaymentProviderInterface
    {
        return $this->resolved[$code] ??= $this->make($code, []);
    }

    public function forAccount(PaymentAccount $account): PaymentProviderInterface
    {
        return $this->resolved['account:'.$account->id] ??= $this->make($account->provider_code, [
            ...($account->settings ?? []),
            ...$account->credentials,
            'account_key' => $account->account_key,
            'webhook_url' => $account->webhookUrl(),
        ]);
    }

    /**
     * provider สำหรับรับ QR ของสาขา: บัญชีที่สาขาเลือกไว้ มิฉะนั้นใช้ default ของระบบ (dev/test = mock)
     *
     * @return array{0: PaymentProviderInterface, 1: ?PaymentAccount}
     */
    public function forQrPayment(Store $store): array
    {
        if ($store->qr_payment_account_id) {
            $account = PaymentAccount::query()->find($store->qr_payment_account_id);
            if (! $account?->isActive()) {
                throw ApiException::unprocessable('qr_account_inactive', 'บัญชีรับชำระ QR ของสาขาถูกปิดใช้งาน');
            }

            return [$this->forAccount($account), $account];
        }
        $default = (string) config('pos.payments.default_qr_provider');
        if ($default === '') {
            throw ApiException::unprocessable('qr_not_configured', 'สาขานี้ยังไม่ได้ตั้งค่าบัญชีรับชำระ QR');
        }

        return [$this->get($default), null];
    }

    public function forAttempt(PaymentAttempt $attempt): PaymentProviderInterface
    {
        if ($attempt->payment_account_id === null) {
            return $this->get($attempt->provider_code);
        }

        return $this->forAccount(PaymentAccount::query()->withoutGlobalScopes()
            ->where('tenant_id', $attempt->tenant_id)->findOrFail($attempt->payment_account_id));
    }

    private function make(string $code, array $accountConfig): PaymentProviderInterface
    {
        $config = config("pos.payments.providers.{$code}");
        if (! $config) {
            throw ApiException::notFound('provider_not_found', 'ไม่พบ payment provider');
        }

        return new $config['class']([...$config, ...$accountConfig]);
    }
}
