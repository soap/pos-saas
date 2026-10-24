<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Tests\Support\PosWorld;
use Tests\TestCase;

/** spec §10 Tenant isolation / Store isolation, D7 Store isolation */
class IsolationTest extends TestCase
{
    use PosWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    public function test_user_cannot_select_tenant_they_do_not_belong_to(): void
    {
        $this->api($this->ownerA, 'GET', 'products', store: $this->storeB1)
            ->assertForbidden()->assertJsonPath('error.code', 'tenant_access_denied');
    }

    public function test_tenant_a_cannot_read_tenant_b_order_by_uuid(): void
    {
        $orderB = $this->createOrder($this->ownerB, [['product_id' => $this->noodleB->id, 'quantity' => 1]], $this->storeB1);

        $this->api($this->ownerA, 'GET', "orders/{$orderB['id']}")->assertNotFound();
        $this->api($this->ownerA, 'POST', "orders/{$orderB['id']}/cancel", ['reason' => 'hack'])->assertNotFound();
        $this->api($this->ownerA, 'PATCH', "products/{$this->noodleB->id}", ['name' => 'x'])->assertNotFound();
    }

    public function test_cannot_order_product_of_another_tenant(): void
    {
        $this->api($this->ownerA, 'POST', 'orders', [
            'order_type' => 'takeaway',
            'items' => [['product_id' => $this->noodleB->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonPath('error.code', 'product_not_found');
    }

    public function test_store_id_from_body_is_ignored(): void
    {
        $order = $this->createOrder($this->ownerA, [$this->noodleIntent(1)]);
        $this->assertSame($this->storeA1->id, $order['store_id']);

        $res = $this->api($this->ownerA, 'POST', 'orders', [
            'order_type' => 'takeaway', 'store_id' => $this->storeB1->id, 'tenant_id' => $this->tenantB->id,
            'items' => [$this->noodleIntent(1)],
        ])->assertCreated();
        $this->assertSame($this->storeA1->id, $res->json('data.store_id'));
    }

    public function test_store_member_cannot_access_other_store_in_same_tenant(): void
    {
        $this->api($this->cashierA1, 'GET', 'orders', store: $this->storeA2)
            ->assertForbidden()->assertJsonPath('error.code', 'store_access_denied');
    }

    public function test_kitchen_of_store_a1_cannot_see_ticket_of_store_a2_even_with_uuid(): void
    {
        $order = $this->createOrder($this->ownerA, [$this->noodleIntent(1)], $this->storeA2);
        $ticket = $this->api($this->ownerA, 'POST', "orders/{$order['id']}/send-to-kitchen", store: $this->storeA2)->assertCreated()->json('data');

        $this->api($this->kitchenA1, 'GET', "kitchen/tickets/{$ticket['id']}")->assertNotFound();
        $this->api($this->kitchenA1, 'POST', "kitchen/tickets/{$ticket['id']}/acknowledge")->assertNotFound();
        $this->assertCount(0, $this->api($this->kitchenA1, 'GET', 'kitchen/tickets')->assertOk()->json('data'));
    }

    public function test_database_rejects_cross_tenant_foreign_keys(): void
    {
        $this->expectException(QueryException::class);
        // order ของ tenant A ที่ชี้ไป store ของ tenant B ต้องถูก composite FK ปฏิเสธ
        \DB::table('orders')->insert([
            'id' => (string) \Str::uuid(), 'tenant_id' => $this->tenantA->id, 'store_id' => $this->storeB1->id,
            'business_date' => '2026-10-21', 'order_number' => 1, 'order_type' => 'dine_in', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_invalid_uuid_in_path_is_404_not_500(): void
    {
        $this->api($this->ownerA, 'GET', 'orders/not-a-uuid')->assertNotFound();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    }
}
