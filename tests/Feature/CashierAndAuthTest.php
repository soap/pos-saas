<?php

namespace Tests\Feature;

use App\Modules\Identity\Models\User;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Support\PosWorld;
use Tests\TestCase;

/** spec §10 Cashier, Authorization, Audit/outbox, Time/reporting */
class CashierAndAuthTest extends TestCase
{
    use PosWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    public function test_only_one_open_shift_per_store(): void
    {
        $this->openShift($this->cashierA1);
        $this->api($this->ownerA, 'POST', 'cashier-shifts/open', ['opening_float_satang' => 0])
            ->assertStatus(409)->assertJsonPath('error.code', 'shift_already_open');
        // สาขาอื่นเปิดได้
        $this->openShift($this->ownerA, 0, $this->storeA2);
    }

    public function test_close_shift_reconciles_and_cannot_close_twice(): void
    {
        $shift = $this->openShift($this->cashierA1, 50000);
        $order = $this->createOrder($this->cashierA1); // 10000
        $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'cash'])->assertCreated();
        $this->api($this->cashierA1, 'POST', 'cash-movements', ['movement_type' => 'cash_out', 'amount_satang' => 3000, 'reason' => 'ซื้อน้ำแข็ง'])->assertCreated();
        $this->api($this->cashierA1, 'POST', 'cash-movements', ['movement_type' => 'cash_in', 'amount_satang' => 1000, 'reason' => 'เงินทอนเพิ่ม'])->assertCreated();

        // คาดหวัง = 50000 + 10000 - 3000 + 1000 = 58000; นับได้ 57500 → ขาด 500
        $closed = $this->api($this->cashierA1, 'POST', "cashier-shifts/{$shift['id']}/close", ['counted_cash_satang' => 57500])->assertOk();
        $closed->assertJsonPath('data.expected_cash_satang', 58000)
            ->assertJsonPath('data.variance_satang', -500)
            ->assertJsonPath('data.status', 'closed');

        $this->api($this->cashierA1, 'POST', "cashier-shifts/{$shift['id']}/close", ['counted_cash_satang' => 58000])
            ->assertStatus(409)->assertJsonPath('error.code', 'shift_not_open');
        $this->assertSame(1, \DB::table('audit_logs')->where('action', 'cashier.shift_closed')->count());
    }

    public function test_kitchen_role_cannot_do_money_actions(): void
    {
        $order = $this->createOrder();
        $this->api($this->kitchenA1, 'POST', "orders/{$order['id']}/cancel", ['reason' => 'test'])->assertForbidden();
        $this->api($this->kitchenA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'cash'])->assertForbidden();
        $this->api($this->kitchenA1, 'POST', 'cashier-shifts/open', ['opening_float_satang' => 0])->assertForbidden();
        $this->api($this->kitchenA1, 'PATCH', "products/{$this->noodleA->id}", ['base_price_satang' => 1])->assertForbidden();
        $this->api($this->kitchenA1, 'GET', 'reports/sales/daily')->assertForbidden();
    }

    public function test_cashier_cannot_cancel_or_manage_members(): void
    {
        $order = $this->createOrder();
        $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/cancel", ['reason' => 'test'])
            ->assertForbidden()->assertJsonPath('error.code', 'permission_denied');
        $this->api($this->cashierA1, 'GET', 'members')->assertForbidden();
    }

    public function test_login_me_and_context(): void
    {
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'CashierA1@test.local', 'password' => 'password123'])->assertOk();
        $token = $login->json('token');
        $this->assertNotEmpty($token);

        $me = $this->withToken($token)->getJson('/api/v1/me')->assertOk();
        $this->assertSame($this->tenantA->id, $me->json('tenants.0.id'));
        $this->assertCount(1, $me->json('tenants.0.stores'));

        $ctx = $this->withToken($token)->getJson('/api/v1/context', ['X-Tenant-Id' => $this->tenantA->id])->assertOk();
        $this->assertNotContains('payments.refund', $ctx->json('permissions'));

        $this->postJson('/api/v1/auth/login', ['email' => 'cashiera1@test.local', 'password' => 'wrong'])
            ->assertUnauthorized()->assertJsonPath('error.code', 'invalid_credentials');
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed']);
        $this->assertDatabaseMissing('audit_logs', ['metadata' => json_encode(['password' => 'wrong'])]);
    }

    public function test_disabled_user_cannot_login(): void
    {
        User::query()->where('id', $this->cashierA1->id)->update(['status' => 'disabled']);
        $this->postJson('/api/v1/auth/login', ['email' => 'cashiera1@test.local', 'password' => 'password123'])
            ->assertForbidden()->assertJsonPath('error.code', 'account_disabled');
    }

    public function test_owner_adds_member_with_store_scoped_role(): void
    {
        $this->api($this->ownerA, 'POST', 'members', [
            'email' => 'new.cashier@test.local', 'name' => 'New', 'password' => 'password123',
            'role' => 'cashier', 'role_store_id' => $this->storeA2->id, 'store_ids' => [$this->storeA2->id],
        ])->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.role_assigned']);

        // ห้ามใส่สาขาของ tenant อื่น
        $this->api($this->ownerA, 'POST', 'members', [
            'email' => 'x@test.local', 'name' => 'X', 'password' => 'password123', 'role' => 'cashier', 'store_ids' => [$this->storeB1->id],
        ])->assertStatus(422)->assertJsonPath('error.code', 'store_invalid');
    }

    public function test_plan_limit_on_stores(): void
    {
        \DB::table('tenant_features')->insert(['tenant_id' => $this->tenantA->id, 'feature_key' => 'max_stores', 'enabled' => true, 'limit_value' => 2, 'updated_at' => now()]);
        $this->api($this->ownerA, 'POST', 'stores', ['code' => 'A3', 'name' => 'A3'])
            ->assertStatus(422)->assertJsonPath('error.code', 'plan_limit_reached');
    }

    public function test_suspended_subscription_blocks_tenant(): void
    {
        \DB::table('subscriptions')->where('tenant_id', $this->tenantA->id)->update(['status' => 'cancelled']);
        $this->api($this->ownerA, 'GET', 'orders')->assertStatus(402)->assertJsonPath('error.code', 'subscription_inactive');
    }

    public function test_daily_report_uses_store_business_date(): void
    {
        $this->openShift($this->cashierA1);
        Carbon::setTestNow(Carbon::parse('2026-10-21 18:00:00', 'UTC')); // 01:00 วันที่ 22 ที่กรุงเทพ
        $order = $this->createOrder($this->ownerA, [['product_id' => $this->waterA->id, 'quantity' => 3]]);
        $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'cash'])->assertCreated();
        $t = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/send-to-kitchen")->json('data');
        $this->api($this->kitchenA1, 'POST', "kitchen/tickets/{$t['id']}/ready");
        $this->api($this->kitchenA1, 'POST', "kitchen/tickets/{$t['id']}/served");

        $r = $this->api($this->ownerA, 'GET', 'reports/sales/daily?from=2026-10-21&to=2026-10-22')->assertOk();
        $days = collect($r->json('data.days'))->keyBy('business_date');
        $this->assertArrayNotHasKey('2026-10-21', $days->all());
        $this->assertSame(3000, $days['2026-10-22']['total_satang']);
        $this->assertSame(3000, $days['2026-10-22']['payments']['cash']);

        $p = $this->api($this->ownerA, 'GET', 'reports/sales/products?from=2026-10-22')->assertOk();
        $this->assertSame(3, $p->json('data.products.0.quantity'));
        Carbon::setTestNow();
    }

    public function test_outbox_publish_is_retry_safe(): void
    {
        Event::fake([OutboxMessage::class]);
        $this->createOrder();
        $this->artisan('outbox:publish')->assertSuccessful();
        $this->artisan('outbox:publish')->assertSuccessful();
        Event::assertDispatchedTimes(OutboxMessage::class, 1);
        $this->assertSame(0, \DB::table('outbox_events')->whereNull('published_at')->count());
    }
}
