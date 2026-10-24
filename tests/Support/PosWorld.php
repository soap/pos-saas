<?php

namespace Tests\Support;

use App\Modules\Billing\Models\Plan;
use App\Modules\Catalog\Models\ModifierGroup;
use App\Modules\Catalog\Models\ModifierOption;
use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\RbacService;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Tenancy\Models\StoreMembership;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;
use App\Modules\Tenancy\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * โลกทดสอบ: tenant A (สาขา A1, A2) และ tenant B (สาขา B1)
 */
trait PosWorld
{
    use RefreshDatabase;

    protected Tenant $tenantA;

    protected Tenant $tenantB;

    protected Store $storeA1;

    protected Store $storeA2;

    protected Store $storeB1;

    protected User $ownerA;

    protected User $ownerB;

    protected User $cashierA1;

    protected User $kitchenA1;

    protected Product $noodleA;

    protected Product $waterA;

    protected Product $noodleB;

    protected ModifierGroup $noodleTypeA;

    /** @var array<string,ModifierOption> */
    protected array $optA = [];

    protected function setUpWorld(): void
    {
        app(TenantContext::class)->withoutTenancy(fn () => $this->buildWorld());
    }

    private function buildWorld(): void
    {
        app(RbacService::class)->syncSystemRoles();
        Plan::create(['code' => 'pro', 'name' => 'Pro', 'price_satang' => 0, 'billing_interval' => 'month', 'limits' => ['max_stores' => 5, 'max_users' => 20], 'is_active' => true]);

        $prov = app(TenantProvisioner::class);
        $this->tenantA = $prov->provision([
            'name' => 'Tenant A', 'slug' => 'tenant-a', 'plan_code' => 'pro',
            'owner' => ['name' => 'Owner A', 'email' => 'ownerA@test.local', 'password' => 'password123'],
            'store' => ['code' => 'A1', 'name' => 'A1'],
        ]);
        $this->tenantB = $prov->provision([
            'name' => 'Tenant B', 'slug' => 'tenant-b', 'plan_code' => 'pro',
            'owner' => ['name' => 'Owner B', 'email' => 'ownerB@test.local', 'password' => 'password123'],
            'store' => ['code' => 'B1', 'name' => 'B1'],
        ]);
        $this->ownerA = User::query()->where('email', 'ownera@test.local')->first();
        $this->ownerB = User::query()->where('email', 'ownerb@test.local')->first();
        $this->storeA1 = Store::query()->where('tenant_id', $this->tenantA->id)->first();
        $this->storeB1 = Store::query()->where('tenant_id', $this->tenantB->id)->first();
        $this->storeA2 = $prov->createStore($this->tenantA, ['code' => 'A2', 'name' => 'A2'], [$this->ownerA->id]);

        $this->cashierA1 = $this->member($this->tenantA, $this->storeA1, 'cashier', 'cashierA1@test.local');
        $this->kitchenA1 = $this->member($this->tenantA, $this->storeA1, 'kitchen', 'kitchenA1@test.local');

        $this->noodleTypeA = ModifierGroup::create(['tenant_id' => $this->tenantA->id, 'name' => 'เส้น', 'min_select' => 1, 'max_select' => 1, 'is_required' => true]);
        foreach (['small' => ['เส้นเล็ก', 0], 'big' => ['เส้นใหญ่', 0]] as $k => [$n, $d]) {
            $this->optA[$k] = ModifierOption::create(['tenant_id' => $this->tenantA->id, 'modifier_group_id' => $this->noodleTypeA->id, 'name' => $n, 'price_delta_satang' => $d]);
        }
        $extra = ModifierGroup::create(['tenant_id' => $this->tenantA->id, 'name' => 'เพิ่ม', 'min_select' => 0, 'max_select' => 3, 'is_required' => false]);
        $this->optA['egg'] = ModifierOption::create(['tenant_id' => $this->tenantA->id, 'modifier_group_id' => $extra->id, 'name' => 'ไข่', 'price_delta_satang' => 1000]);

        $this->noodleA = Product::create(['tenant_id' => $this->tenantA->id, 'sku' => 'N1', 'name' => 'ก๋วยเตี๋ยวหมู', 'base_price_satang' => 5000]);
        $this->noodleA->modifierGroups()->sync([
            $this->noodleTypeA->id => ['tenant_id' => $this->tenantA->id, 'sort_order' => 0],
            $extra->id => ['tenant_id' => $this->tenantA->id, 'sort_order' => 1],
        ]);
        $this->waterA = Product::create(['tenant_id' => $this->tenantA->id, 'sku' => 'W1', 'name' => 'น้ำเปล่า', 'base_price_satang' => 1000]);
        $this->noodleB = Product::create(['tenant_id' => $this->tenantB->id, 'sku' => 'N1', 'name' => 'B noodle', 'base_price_satang' => 4000]);
    }

    protected function member(Tenant $tenant, Store $store, string $role, string $email): User
    {
        $user = User::create(['name' => $role, 'email' => $email, 'password' => 'password123', 'status' => 'active']);
        $m = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'status' => 'active', 'joined_at' => now()]);
        StoreMembership::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'user_id' => $user->id, 'status' => 'active']);
        $rbac = app(RbacService::class);
        $rbac->assign($m, $rbac->findAssignableRole($tenant->id, $role), $store->id);

        return $user;
    }

    /** query ฝั่ง test นอก request ต้องระบุ tenant ให้ชัด (global scope เป็น fail-closed) */
    protected function inTenant(Tenant $tenant, callable $callback): mixed
    {
        return app(TenantContext::class)->runAs($tenant, null, $callback);
    }

    /** วิธีระบุ tenant ที่ api() ใช้: path | subdomain | header (subclass เปลี่ยนเพื่อรัน test ชุดเดิมซ้ำ) */
    protected string $tenantVia = 'path';

    /** เรียก API ในนาม user พร้อม tenant (ตาม $tenantVia) และ X-Store-Id */
    protected function api(User $user, string $method, string $uri, array $data = [], ?Store $store = null, ?string $idemKey = null, ?Tenant $tenant = null): TestResponse
    {
        Sanctum::actingAs($user);
        $store ??= $this->storeA1;
        $tenant ??= Tenant::query()->findOrFail($store->tenant_id);
        $headers = [
            'X-Store-Id' => $store->id,
            'Accept' => 'application/json',
        ];
        $uri = ltrim($uri, '/');
        $url = match ($this->tenantVia) {
            'path' => "/api/v1/t/{$tenant->slug}/{$uri}",
            'subdomain' => "http://{$tenant->slug}.".config('pos.tenancy.subdomain_base')."/api/v1/{$uri}",
            'header' => "/api/v1/{$uri}",
        };
        if ($this->tenantVia === 'header') {
            $headers['X-Tenant-Id'] = $tenant->id;
        }
        if (strtoupper($method) === 'POST') {
            $headers['Idempotency-Key'] = $idemKey ?? (string) Str::uuid();
        }

        return $this->json($method, $url, $data, $headers);
    }

    protected function noodleIntent(int $qty = 1, array $extra = []): array
    {
        return ['product_id' => $this->noodleA->id, 'quantity' => $qty, 'modifier_option_ids' => [$this->optA['small']->id, ...$extra]];
    }

    protected function createOrder(?User $user = null, ?array $items = null, ?Store $store = null): array
    {
        $res = $this->api($user ?? $this->ownerA, 'POST', 'orders', [
            'order_type' => 'dine_in',
            'items' => $items ?? [$this->noodleIntent(2)],
        ], $store);
        $res->assertCreated();

        return $res->json('data');
    }

    protected function openShift(?User $user = null, int $float = 100000, ?Store $store = null): array
    {
        $res = $this->api($user ?? $this->ownerA, 'POST', 'cashier-shifts/open', ['opening_float_satang' => $float], $store);
        $res->assertCreated();

        return $res->json('data');
    }
}
