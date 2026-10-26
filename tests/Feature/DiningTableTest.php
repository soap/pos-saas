<?php

namespace Tests\Feature;

use Tests\Support\PosWorld;
use Tests\TestCase;

/** ผังโต๊ะ: 1 โต๊ะมีออเดอร์ที่ยังเปิดได้ครั้งละ 1 ใบ และผูกได้เฉพาะโต๊ะของสาขาตัวเอง */
class DiningTableTest extends TestCase
{
    use PosWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    private function createTable(string $name, array $extra = [], $store = null): array
    {
        return $this->api($this->ownerA, 'POST', 'tables', ['name' => $name] + $extra, $store)->assertCreated()->json('data');
    }

    private function openOnTable(string $tableId, string $type = 'dine_in')
    {
        return $this->api($this->cashierA1, 'POST', 'orders', [
            'order_type' => $type,
            'dining_table_id' => $tableId,
            'items' => [$this->noodleIntent()],
        ]);
    }

    public function test_table_names_are_unique_per_store_ignoring_case(): void
    {
        $this->createTable('A1', ['zone' => 'ในร้าน', 'seats' => 4]);

        $this->api($this->ownerA, 'POST', 'tables', ['name' => 'a1'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['name']]]);
        // สาขาอื่นใช้ชื่อเดิมได้
        $this->createTable('A1', store: $this->storeA2);
    }

    public function test_cashier_can_view_but_not_manage_tables(): void
    {
        $this->createTable('A1');

        $this->api($this->cashierA1, 'GET', 'tables')->assertOk()->assertJsonCount(1, 'data');
        $this->api($this->cashierA1, 'POST', 'tables', ['name' => 'B1'])->assertForbidden();
    }

    public function test_order_on_table_uses_table_name_and_occupies_it_until_closed(): void
    {
        $table = $this->createTable('A1');

        $order = $this->openOnTable($table['id'])->assertCreated()
            ->assertJsonPath('data.dining_table_id', $table['id'])
            ->assertJsonPath('data.table_label', 'A1')
            ->json('data');
        $this->api($this->cashierA1, 'GET', 'tables')->assertJsonPath('data.0.open_order.id', $order['id']);

        $this->openOnTable($table['id'])
            ->assertStatus(409)->assertJsonPath('error.code', 'table_occupied')->assertJsonPath('error.details.order_id', $order['id']);

        $this->api($this->ownerA, 'POST', "orders/{$order['id']}/cancel", ['reason' => 'ลูกค้าเปลี่ยนใจ'])->assertOk();
        $this->api($this->cashierA1, 'GET', 'tables')->assertJsonPath('data.0.open_order', null);
        $this->openOnTable($table['id'])->assertCreated();
    }

    public function test_cannot_use_table_of_another_store_inactive_table_or_takeaway(): void
    {
        $otherStoreTable = $this->createTable('X1', store: $this->storeA2);
        $this->openOnTable($otherStoreTable['id'])->assertStatus(422)->assertJsonPath('error.code', 'table_invalid');

        $inactive = $this->createTable('A9');
        $this->api($this->ownerA, 'PATCH', "tables/{$inactive['id']}", ['is_active' => false])->assertOk();
        $this->openOnTable($inactive['id'])->assertStatus(422)->assertJsonPath('error.code', 'table_invalid');

        $table = $this->createTable('A1');
        $this->openOnTable($table['id'], 'takeaway')->assertStatus(422)->assertJsonPath('error.code', 'table_requires_dine_in');
    }

    public function test_move_order_to_free_table_but_not_to_occupied_table(): void
    {
        $a1 = $this->createTable('A1');
        $a2 = $this->createTable('A2');
        $a3 = $this->createTable('A3');
        $order = $this->openOnTable($a1['id'])->json('data');
        $this->openOnTable($a3['id'])->assertCreated();

        $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/move-table", ['dining_table_id' => $a3['id']])
            ->assertStatus(409)->assertJsonPath('error.code', 'table_occupied');

        $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/move-table", ['dining_table_id' => $a2['id']])
            ->assertOk()->assertJsonPath('data.dining_table_id', $a2['id'])->assertJsonPath('data.table_label', 'A2');
        $this->openOnTable($a1['id'])->assertCreated();
        $this->assertDatabaseHas('order_events', ['order_id' => $order['id'], 'event_type' => 'order.table_moved']);
    }
}
