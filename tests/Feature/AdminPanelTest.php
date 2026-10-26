<?php

namespace Tests\Feature;

use App\Filament\Resources\Plans\Pages\ManagePlans;
use App\Filament\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Resources\Tenants\Pages\EditTenant;
use App\Filament\Resources\Tenants\Pages\ListTenants;
use App\Filament\Resources\Tenants\Pages\ViewTenant;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\Login;
use Livewire\Livewire;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Platform admin panel (Filament, /admin)
 *
 * Livewire::test ข้าม route middleware ทั้งหมด จึงเปิด platform scope เองในส่วน arrange
 * ส่วนการ wire middleware จริง (รวม Livewire update request) ทดสอบผ่าน HTTP
 */
class AdminPanelTest extends TestCase
{
    use PosWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_tenant_owner_without_platform_admin_flag_gets_403(): void
    {
        $this->actingAs($this->ownerA)->get('/admin/tenants')->assertForbidden();
    }

    public function test_inactive_platform_admin_gets_403(): void
    {
        $admin = $this->platformAdmin();
        $admin->forceFill(['status' => 'disabled'])->save();

        $this->actingAs($admin)->get('/admin/tenants')->assertForbidden();
    }

    public function test_tenant_list_shows_every_tenant_with_its_plan(): void
    {
        $this->actingAs($this->platformAdmin())->get('/admin/tenants')
            ->assertOk()
            ->assertSee('Tenant A')
            ->assertSee('Tenant B')
            ->assertSee('Pro');
    }

    public function test_livewire_update_request_on_tenant_list_runs_across_tenants(): void
    {
        $this->actingAs($this->platformAdmin());
        $page = $this->get('/admin/tenants')->assertOk()->getContent();

        preg_match_all('/wire:snapshot="([^"]+)"/', $page, $matches);
        $snapshot = collect($matches[1])
            ->map(fn (string $raw) => html_entity_decode($raw, ENT_QUOTES))
            ->first(fn (string $json) => json_decode($json, true)['memo']['name'] === ListTenants::class);
        $this->assertNotNull($snapshot);

        $response = $this->withHeader('X-Livewire', 'true')->postJson(app('livewire')->getUpdateUri(), [
            '_token' => csrf_token(),
            'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]],
        ]);

        $response->assertOk();
        $html = $response->json('components.0.effects.html');
        $this->assertStringContainsString('Tenant A', $html);
        $this->assertStringContainsString('Tenant B', $html);
    }

    public function test_view_page_shows_usage_against_plan_limits(): void
    {
        $this->actingAs($this->platformAdmin())->get("/admin/tenants/{$this->tenantA->id}")
            ->assertOk()
            ->assertSee('2 / 5')
            ->assertSee('3 / 20');
    }

    public function test_create_page_provisions_tenant_owner_store_and_subscription(): void
    {
        $this->actAsPlatformAdmin();

        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Tenant C',
                'slug' => 'tenant-c',
                'timezone' => 'Asia/Bangkok',
                'plan_code' => 'pro',
                'owner' => ['name' => 'Owner C', 'email' => 'ownerC@test.local', 'password' => 'password123'],
                'store' => ['code' => 'C1', 'name' => 'สาขา C1'],
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $tenant = Tenant::query()->where('slug', 'tenant-c')->firstOrFail();
        $this->assertSame(['C1'], Store::query()->where('tenant_id', $tenant->id)->pluck('code')->all());
        $this->assertSame('active', Subscription::query()->where('tenant_id', $tenant->id)->value('status'));
        $this->assertDatabaseHas('users', ['email' => 'ownerc@test.local']);
    }

    public function test_create_page_rejects_reserved_slug(): void
    {
        $this->actAsPlatformAdmin();

        Livewire::test(CreateTenant::class)
            ->fillForm(['slug' => 'admin'])
            ->call('create')
            ->assertHasFormErrors(['slug' => 'not_in']);

        $this->assertDatabaseMissing('tenants', ['slug' => 'admin']);
    }

    public function test_create_page_notifies_and_creates_nothing_when_new_owner_has_no_password(): void
    {
        $this->actAsPlatformAdmin();

        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Tenant D',
                'slug' => 'tenant-d',
                'timezone' => 'Asia/Bangkok',
                'plan_code' => 'pro',
                'owner' => ['name' => 'Owner D', 'email' => 'new-owner@test.local', 'password' => null],
                'store' => ['code' => 'D1', 'name' => 'D1'],
            ])
            ->call('create')
            ->assertNotified('ผู้ใช้ใหม่ต้องกำหนดรหัสผ่าน')
            ->assertNoRedirect();

        $this->assertDatabaseMissing('tenants', ['slug' => 'tenant-d']);
    }

    public function test_change_subscription_action_replaces_current_subscription_and_audits(): void
    {
        Plan::create(['code' => 'basic', 'name' => 'Basic', 'price_satang' => 29900, 'billing_interval' => 'month', 'limits' => [], 'is_active' => true]);
        $admin = $this->actAsPlatformAdmin();

        Livewire::test(ViewTenant::class, ['record' => $this->tenantB->id])
            ->callAction('changeSubscription', ['plan_code' => 'basic', 'status' => 'past_due'])
            ->assertNotified('เปลี่ยนแพ็กเกจแล้ว');

        $statuses = Subscription::query()->where('tenant_id', $this->tenantB->id)
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->orderBy('plans.code')->pluck('subscriptions.status', 'plans.code')->all();
        $this->assertSame(['basic' => 'past_due', 'pro' => 'cancelled'], $statuses);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.subscription_changed', 'tenant_id' => $this->tenantB->id, 'actor_user_id' => $admin->id]);
    }

    public function test_edit_page_suspends_tenant_and_audits_change(): void
    {
        $this->actAsPlatformAdmin();

        Livewire::test(EditTenant::class, ['record' => $this->tenantB->id])
            ->fillForm(['status' => 'suspended'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('suspended', $this->tenantB->fresh()->status);
        $this->assertSame('tenant-b', $this->tenantB->fresh()->slug);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.tenant_updated', 'tenant_id' => $this->tenantB->id]);
    }

    public function test_create_plan_action_stores_baht_price_as_satang_with_integer_limits_and_audits(): void
    {
        $this->actAsPlatformAdmin();

        Livewire::test(ManagePlans::class)
            ->callAction(CreateAction::class, [
                'code' => 'max',
                'name' => 'Max',
                'price_satang' => '19.99',
                'billing_interval' => 'year',
                'limits' => ['max_stores' => '10'],
                'is_active' => true,
            ])
            ->assertHasNoFormErrors();

        $plan = Plan::query()->where('code', 'max')->firstOrFail();
        $this->assertSame(1999, $plan->price_satang);
        $this->assertSame(['max_stores' => 10], $plan->limits);
        $this->assertSame('year', $plan->billing_interval);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.plan_created', 'subject_id' => $plan->id]);
    }

    public function test_edit_plan_action_shows_price_in_baht_and_saves_satang(): void
    {
        $this->actAsPlatformAdmin();
        $plan = Plan::query()->where('code', 'pro')->firstOrFail();
        $plan->update(['price_satang' => 59900]);

        Livewire::test(ManagePlans::class)
            ->mountAction(TestAction::make(EditAction::class)->table($plan))
            ->assertSchemaStateSet(['price_satang' => '599.00'])
            ->fillForm(['price_satang' => '649.50'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertSame(64950, $plan->fresh()->price_satang);
    }

    public function test_create_plan_action_rejects_price_with_more_than_two_decimals(): void
    {
        $this->actAsPlatformAdmin();

        Livewire::test(ManagePlans::class)
            ->callAction(CreateAction::class, [
                'code' => 'odd',
                'name' => 'Odd',
                'price_satang' => '10.005',
                'billing_interval' => 'month',
            ])
            ->assertHasFormErrors(['price_satang' => 'decimal']);

        $this->assertDatabaseMissing('plans', ['code' => 'odd']);
    }

    public function test_create_plan_action_rejects_negative_limit(): void
    {
        $this->actAsPlatformAdmin();

        Livewire::test(ManagePlans::class)
            ->callAction(CreateAction::class, [
                'code' => 'bad',
                'name' => 'Bad',
                'price_satang' => 0,
                'billing_interval' => 'month',
                'limits' => ['max_stores' => '-1'],
            ])
            ->assertHasFormErrors(['limits']);

        $this->assertDatabaseMissing('plans', ['code' => 'bad']);
    }

    public function test_platform_admin_without_mfa_is_redirected_to_mfa_setup(): void
    {
        $admin = $this->platformAdmin(withMfa: false);

        $this->actingAs($admin)->get('/admin/tenants')->assertRedirect('/admin/multi-factor-authentication/set-up');
    }

    public function test_login_with_correct_password_alone_does_not_authenticate_admin_with_mfa(): void
    {
        $this->platformAdmin();

        $login = Livewire::test(Login::class)
            ->fillForm(['email' => 'admin@test.local', 'password' => 'password123'])
            ->call('authenticate')
            ->assertNoRedirect();

        $this->assertNotNull($login->get('userUndertakingMultiFactorAuthentication'));
        $this->assertGuest();
    }

    public function test_login_completes_with_valid_authenticator_code(): void
    {
        $admin = $this->platformAdmin();

        Livewire::test(Login::class)
            ->fillForm(['email' => 'admin@test.local', 'password' => 'password123'])
            ->call('authenticate')
            ->set('data.multiFactor.app.code', AppAuthentication::make()->getCurrentCode($admin))
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertAuthenticatedAs($admin);
    }

    public function test_platform_admin_with_only_a_passkey_satisfies_required_mfa(): void
    {
        $admin = $this->platformAdmin(withMfa: false);
        $this->addPasskey($admin);

        $this->actingAs($admin)->get('/admin/tenants')->assertOk();
    }

    public function test_login_with_password_requires_passkey_challenge_for_admin_with_passkey(): void
    {
        $this->addPasskey($this->platformAdmin(withMfa: false));

        $login = Livewire::test(Login::class)
            ->fillForm(['email' => 'admin@test.local', 'password' => 'password123'])
            ->call('authenticate')
            ->assertNoRedirect();

        $this->assertNotNull($login->get('userUndertakingMultiFactorAuthentication'));
        $this->assertGuest();
    }

    public function test_login_page_offers_passwordless_passkey_sign_in(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('fi-passkeys-login-button');
    }

    public function test_generic_passkey_routes_outside_the_panel_return_404(): void
    {
        $this->getJson('/passkeys/login/options')->assertNotFound();
        $this->postJson('/passkeys/login')->assertNotFound();
    }

    private function addPasskey(User $user): void
    {
        $user->passkeys()->create(['name' => 'Test key', 'credential_id' => 'test-credential', 'credential' => ['test' => true]]);
    }

    /** admin ตั้ง MFA แล้วเป็นค่าเริ่มต้น เพราะ panel บังคับ MFA ก่อนเข้าหน้าอื่น */
    private function platformAdmin(bool $withMfa = true): User
    {
        $admin = User::create(['name' => 'admin', 'email' => 'admin@test.local', 'password' => 'password123', 'status' => 'active']);
        $admin->forceFill([
            'is_platform_admin' => true,
            'app_authentication_secret' => $withMfa ? AppAuthentication::make()->generateSecret() : null,
        ])->save();

        return $admin;
    }

    /** แทน UsePlatformScope ที่ Livewire::test ไม่ได้รัน */
    private function actAsPlatformAdmin(): User
    {
        $admin = $this->platformAdmin();
        $this->actingAs($admin);
        app(TenantContext::class)->enterPlatformScope();

        return $admin;
    }
}
