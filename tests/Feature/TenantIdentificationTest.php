<?php

namespace Tests\Feature;

use App\Modules\Identity\Models\User;
use Illuminate\Database\QueryException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MountsAdminApiRoutes;
use Tests\Support\PosWorld;
use Tests\TestCase;

/** การระบุ tenant ผ่าน path / subdomain / header (TenantResolver) */
class TenantIdentificationTest extends TestCase
{
    use MountsAdminApiRoutes, PosWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
        config(['pos.tenancy.subdomain_base' => 'pos.test']);
        Sanctum::actingAs($this->ownerA);
    }

    public function test_each_source_selects_the_tenant(): void
    {
        $this->getJson('/api/v1/t/tenant-a/context')->assertOk()->assertJsonPath('tenant.id', $this->tenantA->id);
        $this->getJson('http://tenant-a.pos.test/api/v1/context')->assertOk()->assertJsonPath('tenant.id', $this->tenantA->id);
        $this->getJson('/api/v1/context', ['X-Tenant-Id' => $this->tenantA->id])->assertOk()->assertJsonPath('tenant.id', $this->tenantA->id);
    }

    public function test_route_ids_still_reach_controller_under_path_prefix(): void
    {
        $order = $this->createOrder();
        $this->getJson("/api/v1/t/tenant-a/orders/{$order['id']}", ['X-Store-Id' => $this->storeA1->id])
            ->assertOk()->assertJsonPath('data.id', $order['id']);
    }

    public function test_sources_that_agree_are_accepted(): void
    {
        $this->getJson('http://tenant-a.pos.test/api/v1/t/tenant-a/context', ['X-Tenant-Id' => $this->tenantA->id])->assertOk();
    }

    public function test_sources_that_disagree_are_rejected(): void
    {
        $this->getJson('/api/v1/t/tenant-a/context', ['X-Tenant-Id' => $this->tenantB->id])
            ->assertStatus(400)->assertJsonPath('error.code', 'tenant_context_conflict');
        $this->getJson('http://tenant-b.pos.test/api/v1/t/tenant-a/context')
            ->assertStatus(400)->assertJsonPath('error.code', 'tenant_context_conflict');
        $this->getJson('http://tenant-a.pos.test/api/v1/t/no-such-shop/context')
            ->assertStatus(400)->assertJsonPath('error.code', 'tenant_context_conflict');
    }

    public function test_unknown_slug_and_non_member_get_the_same_answer(): void
    {
        // ไม่บอกว่า slug มีอยู่จริงหรือไม่
        $this->getJson('/api/v1/t/no-such-shop/context')->assertForbidden()->assertJsonPath('error.code', 'tenant_access_denied');
        $this->getJson('/api/v1/t/tenant-b/context')->assertForbidden()->assertJsonPath('error.code', 'tenant_access_denied');
        $this->getJson('http://no-such-shop.pos.test/api/v1/context')->assertForbidden()->assertJsonPath('error.code', 'tenant_access_denied');
    }

    public function test_missing_tenant_is_rejected(): void
    {
        $this->getJson('/api/v1/context')->assertStatus(400)->assertJsonPath('error.code', 'tenant_context_missing');
        $this->getJson('/api/v1/context', ['X-Tenant-Id' => 'not-a-uuid'])->assertStatus(400)->assertJsonPath('error.code', 'tenant_context_missing');
    }

    public function test_reserved_and_nested_subdomains_are_not_tenants(): void
    {
        $this->getJson('http://app.pos.test/api/v1/context')->assertStatus(400)->assertJsonPath('error.code', 'tenant_context_missing');
        $this->getJson('http://x.tenant-a.pos.test/api/v1/context')->assertStatus(400)->assertJsonPath('error.code', 'tenant_context_missing');
        $this->getJson('http://pos.test/api/v1/context')->assertStatus(400)->assertJsonPath('error.code', 'tenant_context_missing');
        // central endpoint ใช้ได้จาก domain กลาง
        $this->getJson('http://app.pos.test/api/v1/me')->assertOk();
    }

    public function test_subdomain_is_ignored_when_not_configured(): void
    {
        config(['pos.tenancy.subdomain_base' => null]);
        $this->getJson('http://tenant-a.pos.test/api/v1/context')->assertStatus(400)->assertJsonPath('error.code', 'tenant_context_missing');
    }

    public function test_malformed_slug_in_path_is_404(): void
    {
        $this->getJson('/api/v1/t/Tenant_A/context')->assertNotFound();
        $this->getJson('/api/v1/t/-tenant/context')->assertNotFound();
    }

    public function test_tenant_slug_must_be_a_valid_dns_label(): void
    {
        $this->mountAdminApiRoutes();
        $admin = User::create(['name' => 'admin', 'email' => 'admin@test.local', 'password' => 'password123', 'status' => 'active']);
        $admin->forceFill(['is_platform_admin' => true])->save();
        Sanctum::actingAs($admin);

        $payload = fn (string $slug) => [
            'name' => 'Shop', 'slug' => $slug, 'plan_code' => 'pro',
            'owner' => ['name' => 'O', 'email' => "{$slug}@test.local", 'password' => 'password123'],
            'store' => ['code' => 'S1', 'name' => 'S1'],
        ];
        foreach (['Shop', 'my_shop', '-shop', 'shop-', 'app', 'www', str_repeat('a', 64), 'tenant-a'] as $bad) {
            $this->postJson('/api/v1/admin/tenants', $payload($bad))->assertStatus(422);
        }
        $this->postJson('/api/v1/admin/tenants', $payload('shop-2'))->assertCreated();
    }

    public function test_database_rejects_malformed_slug(): void
    {
        $this->expectException(QueryException::class);
        \DB::table('tenants')->insert(['id' => (string) \Str::uuid(), 'name' => 'x', 'slug' => 'Bad_Slug', 'created_at' => now(), 'updated_at' => now()]);
    }
}
