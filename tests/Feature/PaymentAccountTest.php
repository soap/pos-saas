<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\PosWorld;
use Tests\Support\QrPaymentAccounts;
use Tests\TestCase;

/** spec ภาคผนวก B7 — บัญชีรับชำระ QR ต่อ tenant */
class PaymentAccountTest extends TestCase
{
    use PosWorld, QrPaymentAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    public function test_created_account_returns_webhook_url_and_never_exposes_or_stores_plain_credentials(): void
    {
        $account = $this->createOmiseAccount();

        $this->assertSame(['secret_key', 'webhook_secret'], $account['configured_credentials']);
        $this->assertArrayNotHasKey('credentials', $account);
        $this->assertStringNotContainsString('skey_test_5xyz', json_encode($this->api($this->ownerA, 'GET', 'payment-accounts')->json()));
        $this->assertMatchesRegularExpression('#/api/v1/webhooks/payments/omise/pa_[a-z0-9]{32}$#', $account['webhook_url']);
        $this->assertStringNotContainsString('skey_test_5xyz', DB::table('payment_accounts')->value('credentials'));
        $this->assertDatabaseHas('stores', ['id' => $this->storeA1->id, 'qr_payment_account_id' => $account['id']]);
    }

    public function test_store_list_shows_assigned_qr_payment_account(): void
    {
        $account = $this->createOmiseAccount();

        $stores = collect($this->api($this->ownerA, 'GET', 'stores')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame($account['id'], $stores[$this->storeA1->id]['qr_payment_account_id']);
    }

    public function test_cashier_cannot_create_payment_account_403(): void
    {
        $this->api($this->cashierA1, 'POST', 'payment-accounts', [
            'provider_code' => 'omise', 'name' => 'x', 'credentials' => ['secret_key' => 'skey_test_1', 'webhook_secret' => 'eA=='],
        ])->assertForbidden();
        $this->assertDatabaseCount('payment_accounts', 0);
    }

    public function test_easyslip_account_rejects_invalid_promptpay_id_with_422(): void
    {
        $this->api($this->ownerA, 'POST', 'payment-accounts', [
            'provider_code' => 'easyslip', 'name' => 'x',
            'credentials' => ['api_key' => 'k', 'webhook_secret' => 's'],
            'settings' => ['promptpay_id' => '12345'],
        ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['settings.promptpay_id']]]);
    }

    public function test_store_cannot_use_payment_account_of_another_tenant_422(): void
    {
        $accountOfA = $this->createOmiseAccount();

        $this->api($this->ownerB, 'PUT', "stores/{$this->storeB1->id}/qr-payment-account", ['payment_account_id' => $accountOfA['id']], $this->storeB1)
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['payment_account_id']]]);
        $this->assertDatabaseHas('stores', ['id' => $this->storeB1->id, 'qr_payment_account_id' => null]);
    }

    public function test_rotating_credentials_keeps_webhook_url_and_is_audited(): void
    {
        $account = $this->createOmiseAccount();

        $rotated = $this->api($this->ownerA, 'PATCH', "payment-accounts/{$account['id']}", [
            'credentials' => ['secret_key' => 'skey_test_new', 'webhook_secret' => 'bmV3'],
        ])->assertOk()->json('data');

        $this->assertSame($account['webhook_url'], $rotated['webhook_url']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment_account.updated', 'subject_id' => $account['id']]);
    }
}
