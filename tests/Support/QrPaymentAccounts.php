<?php

namespace Tests\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Testing\TestResponse;

/** ตั้งค่าบัญชีรับชำระ QR ผ่าน API และยิง webhook ด้วย raw body (ใช้คู่กับ PosWorld) */
trait QrPaymentAccounts
{
    /** base64 ตามรูปแบบ webhook secret ของ Omise */
    protected string $omiseWebhookSecret = 'c2VjcmV0LWZvci1vbWlzZS13ZWJob29rcw==';

    protected string $easySlipWebhookSecret = 'easyslip-webhook-secret';

    /** @return array<string, mixed> response data ของบัญชีที่สร้าง */
    protected function createOmiseAccount(?User $owner = null, ?Store $store = null): array
    {
        return $this->createQrAccount([
            'provider_code' => 'omise',
            'name' => 'Omise หลัก',
            'credentials' => ['secret_key' => 'skey_test_5xyz', 'webhook_secret' => $this->omiseWebhookSecret],
            'settings' => ['public_key' => 'pkey_test_5xyz'],
        ], $owner, $store);
    }

    /** @return array<string, mixed> */
    protected function createEasySlipAccount(?User $owner = null, ?Store $store = null): array
    {
        return $this->createQrAccount([
            'provider_code' => 'easyslip',
            'name' => 'PromptPay ร้าน',
            'credentials' => ['api_key' => 'es-api-key', 'webhook_secret' => $this->easySlipWebhookSecret],
            'settings' => ['promptpay_id' => '081-234-5678'],
        ], $owner, $store);
    }

    /** สร้างบัญชีแล้วตั้งให้สาขารับ QR ด้วยบัญชีนี้ */
    private function createQrAccount(array $input, ?User $owner, ?Store $store): array
    {
        $owner ??= $this->ownerA;
        $store ??= $this->storeA1;
        $account = $this->api($owner, 'POST', 'payment-accounts', $input, $store)->assertCreated()->json('data');
        $this->api($owner, 'PUT', "stores/{$store->id}/qr-payment-account", ['payment_account_id' => $account['id']], $store)->assertOk();

        return $account;
    }

    /** @param array<string, string> $headers */
    protected function postWebhook(string $url, string $body, array $headers): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', $url, [], [], [], $server, $body);
    }
}
