<?php

namespace Tests\Feature;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Models\User;
use App\Modules\Ordering\Models\Order;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContextMissing;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\PosWorld;
use Tests\TestCase;

/** fail-closed tenant scope + ไล่ทุก endpoint ว่า tenant A แตะข้อมูล tenant B ไม่ได้ */
class TenantScopeTest extends TestCase
{
    use PosWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    public function test_query_without_tenant_context_fails_closed(): void
    {
        $this->expectException(TenantContextMissing::class);
        Product::query()->count();
    }

    public function test_relation_query_without_tenant_context_fails_closed(): void
    {
        $this->expectException(TenantContextMissing::class);
        $this->tenantA->stores()->get();
    }

    public function test_run_as_scopes_to_tenant_and_restores_previous_context(): void
    {
        $ctx = app(TenantContext::class);

        $tenantIds = $ctx->runAs($this->tenantB, null, fn () => Product::query()->pluck('tenant_id')->unique()->all());
        $this->assertSame([$this->tenantB->id], $tenantIds);

        $nested = $ctx->runAs($this->tenantA, $this->storeA1, function () use ($ctx) {
            $inner = $ctx->runAs($this->tenantB, null, fn () => $ctx->tenantId());

            return [$inner, $ctx->tenantId(), $ctx->storeId()];
        });
        $this->assertSame([$this->tenantB->id, $this->tenantA->id, $this->storeA1->id], $nested);
        $this->assertFalse($ctx->hasTenant());
    }

    public function test_run_as_rejects_store_of_another_tenant(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(TenantContext::class)->runAs($this->tenantA, $this->storeB1, fn () => null);
    }

    public function test_without_tenancy_is_explicit_and_temporary(): void
    {
        $ctx = app(TenantContext::class);
        $this->assertSame(3, $ctx->withoutTenancy(fn () => Product::query()->count()));
        $this->assertFalse($ctx->isUnscoped());

        try {
            $ctx->withoutTenancy(fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }
        $this->assertFalse($ctx->isUnscoped(), 'exception ต้องไม่ทำให้ค้างอยู่ใน unscoped mode');
    }

    public function test_tenant_scope_wins_over_where_on_other_tenant(): void
    {
        $ids = $this->inTenant($this->tenantA, fn () => Product::query()->where('tenant_id', $this->tenantB->id)->pluck('id'));
        $this->assertCount(0, $ids);
    }

    public function test_me_and_tenant_list_show_only_own_memberships(): void
    {
        // user ที่อยู่ทั้ง 2 tenant แต่เป็นสมาชิกเฉพาะสาขา A1
        $multi = $this->member($this->tenantA, $this->storeA1, 'cashier', 'multi@test.local');
        $this->inTenant($this->tenantB, fn () => TenantMembership::create([
            'tenant_id' => $this->tenantB->id, 'user_id' => $multi->id, 'status' => 'active', 'joined_at' => now(),
        ]));

        Sanctum::actingAs($multi);
        $me = $this->getJson('/api/v1/me')->assertOk();
        $tenants = collect($me->json('tenants'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$this->tenantA->id, $this->tenantB->id], $tenants->keys()->all());
        $this->assertSame([$this->storeA1->id], collect($tenants[$this->tenantA->id]['stores'])->pluck('id')->all());
        $this->assertSame([], $tenants[$this->tenantB->id]['stores']);

        Sanctum::actingAs($this->ownerA);
        $this->assertSame([$this->tenantA->id], collect($this->getJson('/api/v1/tenants')->assertOk()->json('data'))->pluck('id')->all());
        $this->assertSame([$this->tenantA->id], collect($this->getJson('/api/v1/me')->assertOk()->json('tenants'))->pluck('id')->all());
    }

    public function test_platform_admin_routes_work_across_tenants(): void
    {
        $admin = User::create(['name' => 'admin', 'email' => 'admin@test.local', 'password' => 'password123', 'status' => 'active']);
        $admin->forceFill(['is_platform_admin' => true])->save();
        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/admin/tenants/{$this->tenantB->id}/usage")->assertOk()
            ->assertJsonPath('data.stores', 1)->assertJsonPath('data.members', 1);

        $res = $this->postJson('/api/v1/admin/tenants', [
            'name' => 'Tenant C', 'slug' => 'tenant-c', 'plan_code' => 'pro',
            'owner' => ['name' => 'Owner C', 'email' => 'ownerC@test.local', 'password' => 'password123'],
            'store' => ['code' => 'C1', 'name' => 'C1'],
        ])->assertCreated();
        $this->assertSame(['C1'], collect($res->json('data.stores'))->pluck('code')->all());

        // บัญชีธรรมดาใช้ไม่ได้
        Sanctum::actingAs($this->ownerA);
        $this->getJson("/api/v1/admin/tenants/{$this->tenantB->id}/usage")->assertForbidden();
    }

    public function test_seeder_runs_without_tenant_context(): void
    {
        $this->seed(DatabaseSeeder::class);

        $tenant = Tenant::query()->where('slug', 'p-prateep')->firstOrFail();
        $this->assertSame(8, $this->inTenant($tenant, fn () => Product::query()->count()));
    }

    public function test_tenant_a_cannot_touch_any_tenant_b_resource_by_id(): void
    {
        $b = $this->tenantBResources();
        $snapshot = fn () => [
            DB::table('orders')->where('tenant_id', $this->tenantB->id)->orderBy('id')->get()->toArray(),
            DB::table('order_items')->where('tenant_id', $this->tenantB->id)->orderBy('id')->get()->toArray(),
            DB::table('payments')->where('tenant_id', $this->tenantB->id)->orderBy('id')->get()->toArray(),
            DB::table('refunds')->where('tenant_id', $this->tenantB->id)->count(),
            DB::table('cashier_shifts')->where('tenant_id', $this->tenantB->id)->orderBy('id')->get()->toArray(),
            DB::table('kitchen_tickets')->where('tenant_id', $this->tenantB->id)->orderBy('id')->get()->toArray(),
            DB::table('print_jobs')->where('tenant_id', $this->tenantB->id)->orderBy('id')->get()->toArray(),
            DB::table('store_devices')->where('tenant_id', $this->tenantB->id)->orderBy('id')->get()->toArray(),
            DB::table('categories')->where('tenant_id', $this->tenantB->id)->orderBy('id')->get()->toArray(),
            DB::table('products')->where('tenant_id', $this->tenantB->id)->orderBy('id')->get()->toArray(),
            DB::table('store_products')->where('tenant_id', $this->tenantB->id)->count(),
            DB::table('membership_roles')->where('tenant_id', $this->tenantB->id)->count(),
        ];
        $before = $snapshot();

        $attempts = [
            ['GET', "orders/{$b['order']}"],
            ['GET', "orders/{$b['order']}/events"],
            ['GET', "orders/{$b['order']}/receipt"],
            ['POST', "orders/{$b['order']}/items", ['items' => [$this->noodleIntent(1)]]],
            ['PATCH', "orders/{$b['order']}/items/{$b['item']}", ['quantity' => 2]],
            ['DELETE', "orders/{$b['order']}/items/{$b['item']}"],
            ['POST', "orders/{$b['order']}/discount", ['discount_satang' => 100, 'reason' => 'mistake']],
            ['POST', "orders/{$b['order']}/cancel", ['reason' => 'mistake']],
            ['POST', "orders/{$b['order']}/send-to-kitchen"],
            ['POST', "orders/{$b['order']}/payments", ['method' => 'cash']],
            ['GET', "payments/{$b['payment']}"],
            ['POST', "payments/{$b['payment']}/cancel"],
            ['POST', "payments/{$b['payment']}/refunds", ['amount_satang' => 100, 'reason' => 'mistake']],
            ['GET', "cashier-shifts/{$b['shift']}"],
            ['POST', "cashier-shifts/{$b['shift']}/close", ['counted_cash_satang' => 0]],
            ['GET', "kitchen/tickets/{$b['ticket']}"],
            ['POST', "kitchen/tickets/{$b['ticket']}/acknowledge"],
            ['POST', "devices/{$b['device']}/heartbeat"],
            ['POST', "print-jobs/{$b['print_job']}/result", ['status' => 'printed']],
            ['POST', "print-jobs/{$b['print_job']}/retry"],
            ['PATCH', "categories/{$b['category']}", ['name' => 'x']],
            ['PATCH', "products/{$this->noodleB->id}", ['name' => 'x']],
            ['PUT', "products/{$this->noodleB->id}/store-price", ['price_satang' => 1, 'is_available' => true]],
            ['DELETE', "members/{$b['membership']}/roles/owner"],
        ];

        foreach ($attempts as $attempt) {
            [$method, $uri] = $attempt;
            $res = $this->api($this->ownerA, $method, $uri, $attempt[2] ?? []);
            $this->assertSame(404, $res->status(), "{$method} {$uri} → {$res->status()} ".$res->getContent());
        }

        $this->assertEquals($before, $snapshot(), 'ข้อมูลของ tenant B ต้องไม่ถูกแก้แม้แต่แถวเดียว');
    }

    public function test_tenant_a_lists_never_contain_tenant_b_ids(): void
    {
        $b = $this->tenantBResources();
        $bIds = array_values($b + ['tenant' => $this->tenantB->id, 'store' => $this->storeB1->id, 'product' => $this->noodleB->id]);

        $range = 'from='.now()->subDays(7)->toDateString().'&to='.now()->addDays(1)->toDateString();
        $lists = [
            'context', 'stores', 'members', 'categories', 'products', 'modifier-groups', 'menu',
            'orders', 'kitchen/tickets', 'cashier-shifts', 'cashier-shifts/current', 'devices', 'print-jobs',
            "reports/sales/daily?{$range}", "reports/sales/products?{$range}",
        ];
        foreach ($lists as $uri) {
            $res = $this->api($this->ownerA, 'GET', $uri);
            $this->assertContains($res->status(), [200, 404], "GET {$uri} → {$res->status()} ".$res->getContent());
            foreach ($bIds as $id) {
                $this->assertStringNotContainsString($id, $res->getContent(), "GET {$uri} รั่ว id ของ tenant B");
            }
        }
    }

    /** สร้างข้อมูลครบทุกชนิดใน tenant B ผ่าน API ในนาม ownerB */
    private function tenantBResources(): array
    {
        $category = $this->inTenant($this->tenantB, fn () => Category::create(['tenant_id' => $this->tenantB->id, 'name' => 'B cat']));
        $order = $this->createOrder($this->ownerB, [['product_id' => $this->noodleB->id, 'quantity' => 1]], $this->storeB1);
        $paidOrder = $this->createOrder($this->ownerB, [['product_id' => $this->noodleB->id, 'quantity' => 1]], $this->storeB1);
        $shift = $this->openShift($this->ownerB, 10000, $this->storeB1);
        $payment = $this->api($this->ownerB, 'POST', "orders/{$paidOrder['id']}/payments", ['method' => 'cash', 'tendered_satang' => 4000], $this->storeB1)
            ->assertCreated()->json('data');
        $ticket = $this->api($this->ownerB, 'POST', "orders/{$order['id']}/send-to-kitchen", store: $this->storeB1)->assertCreated()->json('data');
        $device = $this->api($this->ownerB, 'POST', 'devices', [
            'device_code' => 'PRN1', 'name' => 'printer', 'device_type' => 'receipt_printer', 'connection_type' => 'browser',
        ], $this->storeB1)->assertCreated()->json('data');
        $printJob = $this->api($this->ownerB, 'POST', 'print-jobs', ['document_type' => 'receipt', 'source_id' => $paidOrder['id']], $this->storeB1)
            ->assertSuccessful()->json('data');
        $membership = $this->inTenant($this->tenantB, fn () => TenantMembership::query()->where('user_id', $this->ownerB->id)->value('id'));

        $this->assertSame($this->tenantB->id, $this->inTenant($this->tenantB, fn () => Order::query()->findOrFail($order['id'])->tenant_id));
        $this->assertNotNull($this->inTenant($this->tenantB, fn () => Store::query()->find($this->storeB1->id)));

        return [
            'category' => $category->id,
            'order' => $order['id'],
            'item' => $order['items'][0]['id'],
            'payment' => $payment['id'],
            'shift' => $shift['id'],
            'ticket' => $ticket['id'],
            'device' => $device['id'],
            'print_job' => $printJob['id'],
            'membership' => $membership,
        ];
    }
}
