<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Soap\LaravelOmise\Facades\Omise;
use Tests\Support\PosWorld;
use Tests\Support\QrPaymentAccounts;
use Tests\TestCase;

/** รอบชำระค่าแพ็กเกจ — PromptPay ผ่านบัญชี Omise แพลตฟอร์ม, ผ่อนผัน 7 วันก่อนระงับ */
class BillingTest extends TestCase
{
    use PosWorld, QrPaymentAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
        config([
            'pos.billing.omise.public_key' => 'pkey_test_platform',
            'pos.billing.omise.secret_key' => 'skey_test_platform',
            'pos.billing.omise.webhook_secret' => $this->omiseWebhookSecret,
        ]);
    }

    public function test_cycle_issues_one_invoice_for_trial_ending_within_lead_days(): void
    {
        $this->freezeSecond();
        $this->putTenantAOnPaidTrial(now()->addDays(3));

        $this->artisan('billing:cycle')->assertSuccessful();
        $this->artisan('billing:cycle')->assertSuccessful();

        $invoices = DB::table('subscription_payments')->where('tenant_id', $this->tenantA->id)->get();
        $this->assertCount(1, $invoices);
        $this->assertSame(59900, (int) $invoices[0]->amount_satang);
        $this->assertSame('pending', $invoices[0]->status);
        $this->assertTrue(Carbon::parse($invoices[0]->billing_period_start)->equalTo(now()->addDays(3)));
        $this->assertTrue(Carbon::parse($invoices[0]->billing_period_end)->equalTo(now()->addDays(3)->addMonthNoOverflow()));
    }

    public function test_free_plan_is_never_invoiced(): void
    {
        $this->artisan('billing:cycle')->assertSuccessful();

        $this->assertDatabaseCount('subscription_payments', 0);
    }

    public function test_unpaid_tenant_keeps_working_during_grace_then_gets_402_but_can_still_open_billing(): void
    {
        $this->putTenantAOnPaidTrial(now()->addDay());
        $this->artisan('billing:cycle')->assertSuccessful();

        $this->travel(2)->days();
        $this->artisan('billing:cycle')->assertSuccessful();
        $this->assertDatabaseHas('subscriptions', ['tenant_id' => $this->tenantA->id, 'status' => 'past_due']);
        $this->api($this->ownerA, 'GET', 'orders')->assertOk();

        $this->travel(7)->days();
        $this->api($this->ownerA, 'GET', 'orders')->assertStatus(402)->assertJsonPath('error.code', 'subscription_inactive');
        $this->api($this->ownerA, 'GET', 'billing')->assertOk()
            ->assertJsonPath('data.usable', false)
            ->assertJsonPath('data.subscription.status', 'past_due')
            ->assertJsonPath('data.invoices.0.status', 'pending');
    }

    public function test_pay_creates_promptpay_charge_on_platform_account_and_reuses_live_qr(): void
    {
        $invoiceId = $this->issueInvoiceForTenantA();
        $this->fakeOmiseCharge('chrg_bill_1');

        $first = $this->api($this->ownerA, 'POST', "billing/invoices/{$invoiceId}/pay")->assertOk()
            ->assertJsonPath('data.qr_image_url', 'https://api.omise.co/charges/chrg_bill_1/documents/docu_1/downloads/qr');
        $this->api($this->ownerA, 'POST', "billing/invoices/{$invoiceId}/pay")->assertOk()
            ->assertJsonPath('data.qr_expires_at', $first->json('data.qr_expires_at'));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => rtrim($request->url(), '/') === 'https://api.omise.co/charges'
            && $request['amount'] === '59900' && $request['source'] === ['type' => 'promptpay']
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('skey_test_platform:')));
    }

    public function test_successful_charge_webhook_marks_invoice_paid_and_extends_subscription_once(): void
    {
        $this->freezeSecond();
        $invoiceId = $this->issueInvoiceForTenantA();
        $this->fakeOmiseCharge('chrg_bill_1');
        $this->api($this->ownerA, 'POST', "billing/invoices/{$invoiceId}/pay")->assertOk();

        $this->sendBillingEvent('chrg_bill_1', 'successful')->assertOk()->assertJsonPath('result', 'processed');
        $this->sendBillingEvent('chrg_bill_1', 'successful')->assertOk()->assertJsonPath('result', 'duplicate');

        $this->assertDatabaseHas('subscription_payments', ['id' => $invoiceId, 'status' => 'paid']);
        $periodEnd = now()->addDays(3)->addMonthNoOverflow();
        $sub = DB::table('subscriptions')->where('tenant_id', $this->tenantA->id)->where('status', '<>', 'cancelled')->first();
        $this->assertSame('active', $sub->status);
        $this->assertTrue(Carbon::parse($sub->current_period_end)->equalTo($periodEnd));
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.invoice_paid', 'tenant_id' => $this->tenantA->id]);
    }

    public function test_payment_after_suspension_starts_a_new_period_from_payment_time(): void
    {
        $this->freezeSecond();
        $invoiceId = $this->issueInvoiceForTenantA();
        $this->travel(20)->days();
        $this->fakeOmiseCharge('chrg_bill_1');
        $this->api($this->ownerA, 'POST', "billing/invoices/{$invoiceId}/pay")->assertOk();

        $this->sendBillingEvent('chrg_bill_1', 'successful')->assertOk();

        $sub = DB::table('subscriptions')->where('tenant_id', $this->tenantA->id)->where('status', '<>', 'cancelled')->first();
        $this->assertTrue(Carbon::parse($sub->current_period_end)->equalTo(now()->addMonthNoOverflow()));
        $this->api($this->ownerA, 'GET', 'orders')->assertOk();
    }

    public function test_failed_charge_leaves_invoice_payable_with_a_new_qr(): void
    {
        $invoiceId = $this->issueInvoiceForTenantA();
        $this->fakeOmiseCharge('chrg_bill_1');
        $this->api($this->ownerA, 'POST', "billing/invoices/{$invoiceId}/pay")->assertOk();

        $this->sendBillingEvent('chrg_bill_1', 'failed')->assertOk()->assertJsonPath('result', 'ignored');

        $this->assertDatabaseHas('subscription_payments', ['id' => $invoiceId, 'status' => 'pending']);
    }

    public function test_webhook_with_wrong_signature_returns_401(): void
    {
        $invoiceId = $this->issueInvoiceForTenantA();
        $this->fakeOmiseCharge('chrg_bill_1');
        $this->api($this->ownerA, 'POST', "billing/invoices/{$invoiceId}/pay")->assertOk();

        $this->sendBillingEvent('chrg_bill_1', 'successful', secret: base64_encode('wrong'))
            ->assertStatus(401)->assertJsonPath('error.code', 'invalid_signature');

        $this->assertDatabaseHas('subscription_payments', ['id' => $invoiceId, 'status' => 'pending']);
    }

    public function test_cashier_without_billing_permission_gets_403(): void
    {
        $this->api($this->cashierA1, 'GET', 'billing')->assertForbidden();
    }

    public function test_owner_of_another_tenant_cannot_pay_tenant_a_invoice(): void
    {
        $invoiceId = $this->issueInvoiceForTenantA();

        $this->api($this->ownerB, 'POST', "billing/invoices/{$invoiceId}/pay", store: $this->storeB1)->assertNotFound();
    }

    private function putTenantAOnPaidTrial(Carbon $trialEndsAt): void
    {
        $planId = DB::table('subscriptions')->where('tenant_id', $this->tenantA->id)->value('plan_id');
        DB::table('plans')->where('id', $planId)->update(['price_satang' => 59900]);
        DB::table('subscriptions')->where('tenant_id', $this->tenantA->id)
            ->update(['status' => 'trialing', 'trial_ends_at' => $trialEndsAt]);
    }

    private function issueInvoiceForTenantA(): string
    {
        $this->putTenantAOnPaidTrial(now()->addDays(3));
        $this->artisan('billing:cycle')->assertSuccessful();

        return DB::table('subscription_payments')->where('tenant_id', $this->tenantA->id)->value('id');
    }

    private function fakeOmiseCharge(string $chargeId): void
    {
        Http::preventStrayRequests();
        Omise::fake(['https://api.omise.co/charges*' => Http::response([
            'object' => 'charge', 'id' => $chargeId, 'status' => 'pending', 'expires_at' => now()->addMinutes(30)->toIso8601ZuluString(),
            'source' => ['type' => 'promptpay', 'scannable_code' => ['image' => ['download_uri' => "https://api.omise.co/charges/{$chargeId}/documents/docu_1/downloads/qr"]]],
        ])]);
    }

    private function sendBillingEvent(string $chargeId, string $status, ?string $secret = null): TestResponse
    {
        $body = json_encode([
            'object' => 'event', 'id' => 'evnt_bill_'.$chargeId, 'key' => 'charge.complete',
            'data' => ['object' => 'charge', 'id' => $chargeId, 'status' => $status, 'transaction' => 'trxn_bill_1'],
        ]);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, base64_decode($secret ?? $this->omiseWebhookSecret));

        return $this->postWebhook('/api/v1/webhooks/billing/omise', $body, ['Omise-Signature' => $signature, 'Omise-Signature-Timestamp' => $timestamp]);
    }
}
