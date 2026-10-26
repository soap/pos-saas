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

    public function test_create_plan_action_stores_integer_limits_and_audits(): void
    {
        $this->actAsPlatformAdmin();

        Livewire::test(ManagePlans::class)
            ->callAction(CreateAction::class, [
                'code' => 'max',
                'name' => 'Max',
                'price_satang' => 99900,
                'billing_interval' => 'year',
                'limits' => ['max_stores' => '10'],
                'is_active' => true,
            ])
            ->assertHasNoFormErrors();

        $plan = Plan::query()->where('code', 'max')->firstOrFail();
        $this->assertSame(['max_stores' => 10], $plan->limits);
        $this->assertSame('year', $plan->billing_interval);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.plan_created', 'subject_id' => $plan->id]);
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

    private function platformAdmin(): User
    {
        $admin = User::create(['name' => 'admin', 'email' => 'admin@test.local', 'password' => 'password123', 'status' => 'active']);
        $admin->forceFill(['is_platform_admin' => true])->save();

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
