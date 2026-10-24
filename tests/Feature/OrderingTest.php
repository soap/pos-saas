<?php

namespace Tests\Feature;

use App\Modules\Ordering\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\PosWorld;
use Tests\TestCase;

/** spec §10 Pricing, Order number, Idempotency */
class OrderingTest extends TestCase
{
    use PosWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    public function test_server_calculates_totals_and_ignores_client_prices(): void
    {
        $order = $this->createOrder($this->ownerA, [
            ['product_id' => $this->noodleA->id, 'quantity' => 2, 'modifier_option_ids' => [$this->optA['small']->id, $this->optA['egg']->id], 'unit_price_satang' => 1],
            ['product_id' => $this->waterA->id, 'quantity' => 1, 'line_total_satang' => 0],
        ]);
        // (5000 + 1000) * 2 + 1000 = 13000
        $this->assertSame(13000, $order['subtotal_satang']);
        $this->assertSame(13000, $order['total_satang']);
        $this->assertSame(0, $order['tax_satang']);
        $this->assertSame(1000, $order['items'][0]['modifiers_total_satang']);
    }

    public function test_price_change_does_not_change_existing_snapshot(): void
    {
        $order = $this->createOrder($this->ownerA, [$this->noodleIntent(1)]);
        $this->api($this->ownerA, 'PATCH', "products/{$this->noodleA->id}", ['name' => 'ก๋วยเตี๋ยวหมูใหม่', 'base_price_satang' => 9900])->assertOk();

        $again = $this->api($this->ownerA, 'GET', "orders/{$order['id']}")->assertOk()->json('data');
        $this->assertSame(5000, $again['items'][0]['unit_price_satang']);
        $this->assertSame('ก๋วยเตี๋ยวหมู', $again['items'][0]['name']);
        $this->assertSame(5000, $again['total_satang']);
    }

    public function test_store_price_override_is_used(): void
    {
        $this->api($this->ownerA, 'PUT', "products/{$this->noodleA->id}/store-price", ['price_satang' => 6500, 'is_available' => true])->assertOk();
        $order = $this->createOrder($this->ownerA, [$this->noodleIntent(1)]);
        $this->assertSame(6500, $order['total_satang']);

        $this->api($this->ownerA, 'PUT', "products/{$this->noodleA->id}/store-price", ['price_satang' => 6500, 'is_available' => false])->assertOk();
        $this->api($this->ownerA, 'POST', 'orders', ['order_type' => 'dine_in', 'items' => [$this->noodleIntent(1)]])
            ->assertStatus(422)->assertJsonPath('error.code', 'product_unavailable');
    }

    public function test_required_modifier_group_is_enforced(): void
    {
        $this->api($this->ownerA, 'POST', 'orders', [
            'order_type' => 'dine_in', 'items' => [['product_id' => $this->noodleA->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonPath('error.code', 'modifier_selection_invalid');

        $this->api($this->ownerA, 'POST', 'orders', [
            'order_type' => 'dine_in', 'items' => [$this->noodleIntent(1, [$this->optA['big']->id])],
        ])->assertStatus(422)->assertJsonPath('error.code', 'modifier_selection_invalid');
    }

    public function test_order_numbers_are_sequential_per_store_and_business_date(): void
    {
        $a = $this->createOrder()['order_number'];
        $b = $this->createOrder()['order_number'];
        $c = $this->createOrder(store: $this->storeA2)['order_number'];
        $this->assertSame([1, 2, 1], [$a, $b, $c]);
        $this->assertSame(3, (int) DB::table('store_order_counters')->where('store_id', $this->storeA1->id)->value('next_number'));
    }

    public function test_business_date_uses_store_timezone(): void
    {
        // 2026-10-21 18:30 UTC = 2026-10-22 01:30 ที่กรุงเทพ
        Carbon::setTestNow(Carbon::parse('2026-10-21 18:30:00', 'UTC'));
        $order = $this->createOrder();
        $this->assertSame('2026-10-22', $order['business_date']);
        Carbon::setTestNow();
    }

    public function test_same_idempotency_key_returns_same_order(): void
    {
        $payload = ['order_type' => 'dine_in', 'items' => [$this->noodleIntent(1)]];
        $first = $this->api($this->ownerA, 'POST', 'orders', $payload, idemKey: 'order-key-0001')->assertCreated();
        $second = $this->api($this->ownerA, 'POST', 'orders', $payload, idemKey: 'order-key-0001')->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $second->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, Order::query()->withoutGlobalScopes()->where('store_id', $this->storeA1->id)->count());

        $this->api($this->ownerA, 'POST', 'orders', ['order_type' => 'takeaway', 'items' => [$this->noodleIntent(3)]], idemKey: 'order-key-0001')
            ->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_reused');
    }

    public function test_idempotency_key_is_required(): void
    {
        Sanctum::actingAs($this->ownerA);
        $this->postJson('/api/v1/orders', ['order_type' => 'dine_in'], [
            'X-Tenant-Id' => $this->tenantA->id, 'X-Store-Id' => $this->storeA1->id,
        ])->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');
    }

    public function test_items_cannot_be_changed_after_sent_to_kitchen(): void
    {
        $order = $this->createOrder();
        $itemId = $order['items'][0]['id'];
        $this->api($this->ownerA, 'PATCH', "orders/{$order['id']}/items/{$itemId}", ['quantity' => 5])->assertOk()
            ->assertJsonPath('data.total_satang', 25000);
        $this->api($this->ownerA, 'POST', "orders/{$order['id']}/send-to-kitchen")->assertCreated();
        $this->api($this->ownerA, 'PATCH', "orders/{$order['id']}/items/{$itemId}", ['quantity' => 1])
            ->assertStatus(409)->assertJsonPath('error.code', 'item_already_sent');
    }

    public function test_discount_and_cancel_are_recorded(): void
    {
        $order = $this->createOrder();
        $this->api($this->ownerA, 'POST', "orders/{$order['id']}/discount", ['discount_satang' => 2000, 'reason' => 'ลูกค้าประจำ'])
            ->assertOk()->assertJsonPath('data.total_satang', 8000);
        $this->api($this->ownerA, 'POST', "orders/{$order['id']}/discount", ['discount_satang' => 999999])
            ->assertStatus(422);
        $this->api($this->ownerA, 'POST', "orders/{$order['id']}/cancel", ['reason' => 'ลูกค้ายกเลิก'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('audit_logs', ['action' => 'order.cancelled', 'subject_id' => $order['id']]);
        $this->assertDatabaseHas('order_events', ['order_id' => $order['id'], 'event_type' => 'order.cancelled']);
        $this->assertDatabaseHas('outbox_events', ['aggregate_id' => $order['id'], 'event_type' => 'order.cancelled']);
    }
}
