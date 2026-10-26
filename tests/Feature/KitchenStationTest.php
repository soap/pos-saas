<?php

namespace Tests\Feature;

use Tests\Support\PosWorld;
use Tests\TestCase;

/** ภาคผนวก D5 — ส่งครัวแตก ticket ตาม station และจอครัวกรองตาม station */
class KitchenStationTest extends TestCase
{
    use PosWorld;

    private array $drinks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
        $this->drinks = $this->api($this->ownerA, 'POST', 'kitchen-stations', ['name' => 'เครื่องดื่ม'])->assertCreated()->json('data');
        $this->api($this->ownerA, 'PATCH', "products/{$this->waterA->id}", ['kitchen_station_id' => $this->drinks['id']])
            ->assertOk()->assertJsonPath('data.kitchen_station_id', $this->drinks['id']);
    }

    /** ก๋วยเตี๋ยว (ครัวหลัก) + น้ำ (เครื่องดื่ม) */
    private function createMixedOrder(): array
    {
        return $this->createOrder(items: [$this->noodleIntent(), ['product_id' => $this->waterA->id, 'quantity' => 2]]);
    }

    private function send(string $orderId)
    {
        return $this->api($this->cashierA1, 'POST', "orders/{$orderId}/send-to-kitchen")->assertCreated();
    }

    public function test_send_splits_one_revision_into_a_ticket_per_station(): void
    {
        $sent = $this->send($this->createMixedOrder()['id']);

        $this->assertCount(2, $sent->json('tickets'));
        // data = ticket ครัวหลัก (คงรูปแบบเดิม)
        $sent->assertJsonPath('data.station', null)->assertJsonPath('data.revision', 1)->assertJsonPath('data.items.0.name', 'ก๋วยเตี๋ยวหมู');
        $sent->assertJsonPath('tickets.1.station.name', 'เครื่องดื่ม')->assertJsonPath('tickets.1.revision', 1)->assertJsonPath('tickets.1.items.0.quantity', 2);
    }

    public function test_kitchen_dashboard_filters_by_station(): void
    {
        $this->send($this->createMixedOrder()['id']);

        $drinks = $this->api($this->kitchenA1, 'GET', "kitchen/tickets?station={$this->drinks['id']}")->assertOk();
        $drinks->assertJsonCount(1, 'data')->assertJsonPath('data.0.items.0.name', 'น้ำเปล่า');
        $this->api($this->kitchenA1, 'GET', 'kitchen/tickets?station=main')->assertJsonCount(1, 'data')->assertJsonPath('data.0.station', null);
        $this->api($this->kitchenA1, 'GET', 'kitchen/tickets')->assertJsonCount(2, 'data');
        $this->api($this->kitchenA1, 'GET', 'kitchen/tickets?station=drinks')->assertStatus(422);
    }

    public function test_order_closes_only_after_every_station_has_served(): void
    {
        $order = $this->createMixedOrder();
        $tickets = collect($this->send($order['id'])->json('tickets'));
        $this->openShift($this->cashierA1);
        $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'cash'])->assertCreated();

        $drinkTicket = $tickets->firstWhere('station.id', $this->drinks['id']);
        $this->api($this->kitchenA1, 'POST', "kitchen/tickets/{$drinkTicket['id']}/ready")->assertOk();
        $this->api($this->kitchenA1, 'POST', "kitchen/tickets/{$drinkTicket['id']}/served")->assertOk();
        $this->api($this->cashierA1, 'GET', "orders/{$order['id']}")->assertJsonPath('data.status', 'placed');

        $mainTicket = $tickets->firstWhere('station', null);
        $this->api($this->kitchenA1, 'POST', "kitchen/tickets/{$mainTicket['id']}/ready")->assertOk();
        $this->api($this->kitchenA1, 'POST', "kitchen/tickets/{$mainTicket['id']}/served")->assertOk();
        $this->api($this->cashierA1, 'GET', "orders/{$order['id']}")->assertJsonPath('data.status', 'completed');
    }

    public function test_items_of_inactive_station_go_to_main_kitchen(): void
    {
        $this->api($this->ownerA, 'PATCH', "kitchen-stations/{$this->drinks['id']}", ['is_active' => false])->assertOk();

        $sent = $this->send($this->createMixedOrder()['id']);

        $this->assertCount(1, $sent->json('tickets'));
        $sent->assertJsonPath('data.station', null)->assertJsonCount(2, 'data.items');
    }

    public function test_station_rules_names_permissions_and_tenant_isolation(): void
    {
        $this->api($this->ownerA, 'POST', 'kitchen-stations', ['name' => 'เครื่องดื่ม '])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['name']]]);
        $this->api($this->cashierA1, 'POST', 'kitchen-stations', ['name' => 'ย่าง'])->assertForbidden();
        $this->api($this->kitchenA1, 'GET', 'kitchen-stations')->assertOk()->assertJsonCount(1, 'data');

        // tenant B ใช้ station ของ tenant A ไม่ได้
        $this->api($this->ownerB, 'PATCH', "products/{$this->noodleB->id}", ['kitchen_station_id' => $this->drinks['id']], $this->storeB1)
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['kitchen_station_id']]]);
    }
}
