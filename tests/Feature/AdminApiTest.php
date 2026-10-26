<?php

namespace Tests\Feature;

use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Identity\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MountsAdminApiRoutes;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * SaaS Admin API (/api/v1/admin) — routes/api-admin.php ปิดไว้ (token จาก API login ข้าม MFA ได้)
 * test ของ endpoint mount ไฟล์เองเพื่อให้เปิดกลับมาได้โดยไม่พัง
 */
class AdminApiTest extends TestCase
{
    use MountsAdminApiRoutes, PosWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    public function test_admin_api_returns_404_because_it_is_not_loaded(): void
    {
        Sanctum::actingAs($this->platformAdmin());

        $this->getJson('/api/v1/admin/tenants')->assertNotFound();
        $this->postJson('/api/v1/admin/plans', ['code' => 'x'])->assertNotFound();
    }

    public function test_create_plan_returns_201_and_defaults_to_active_with_empty_limits(): void
    {
        $this->mountAdminApiRoutes();
        Sanctum::actingAs($this->platformAdmin());

        $this->postJson('/api/v1/admin/plans', [
            'code' => 'basic', 'name' => 'Basic', 'price_satang' => 29900, 'billing_interval' => 'month',
        ])->assertCreated()->assertJsonPath('data.code', 'basic');

        $plan = Plan::query()->where('code', 'basic')->firstOrFail();
        $this->assertTrue($plan->is_active);
        $this->assertSame([], $plan->limits);
    }

    public function test_subscribe_returns_201_and_cancels_previous_subscription(): void
    {
        $this->mountAdminApiRoutes();
        Plan::create(['code' => 'basic', 'name' => 'Basic', 'price_satang' => 29900, 'billing_interval' => 'month', 'limits' => [], 'is_active' => true]);
        Sanctum::actingAs($this->platformAdmin());

        $this->postJson('/api/v1/admin/subscriptions', [
            'tenant_id' => $this->tenantA->id, 'plan_code' => 'basic', 'status' => 'active',
        ])->assertCreated()->assertJsonPath('data.plan.code', 'basic');

        $this->assertSame(
            ['basic' => 'active', 'pro' => 'cancelled'],
            Subscription::query()->withoutGlobalScopes()->where('tenant_id', $this->tenantA->id)
                ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
                ->orderBy('plans.code')->pluck('subscriptions.status', 'plans.code')->all(),
        );
    }

    private function platformAdmin(): User
    {
        $admin = User::create(['name' => 'admin', 'email' => 'admin@test.local', 'password' => 'password123', 'status' => 'active']);
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }
}
