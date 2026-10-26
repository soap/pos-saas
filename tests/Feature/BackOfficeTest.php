<?php

namespace Tests\Feature;

use Tests\Support\PosWorld;
use Tests\TestCase;

/** API หลังร้าน: ราคาสาขา, แก้กลุ่มตัวเลือก, แก้สาขา, ระงับพนักงาน */
class BackOfficeTest extends TestCase
{
    use PosWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    private function menuPrice(string $productId, $store = null): ?int
    {
        $menu = $this->api($this->ownerA, 'GET', 'menu', store: $store)->assertOk()->json('data');
        $products = collect($menu['categories'])->flatMap(fn ($c) => $c['products'])->concat($menu['uncategorized']);

        return $products->firstWhere('id', $productId)['price_satang'] ?? null;
    }

    public function test_store_price_override_can_be_listed_and_cleared_without_affecting_other_stores(): void
    {
        $this->api($this->ownerA, 'PUT', "products/{$this->noodleA->id}/store-price", ['price_satang' => 6500, 'is_available' => true])->assertOk();
        $this->api($this->ownerA, 'PUT', "products/{$this->waterA->id}/store-price", ['price_satang' => 1000, 'is_available' => false])->assertOk();

        $prices = collect($this->api($this->ownerA, 'GET', 'store-prices')->assertOk()->json('data'))->keyBy('product_id');
        $this->assertSame(6500, $prices[$this->noodleA->id]['price_satang']);
        $this->assertFalse($prices[$this->waterA->id]['is_available']);
        $this->assertSame(6500, $this->menuPrice($this->noodleA->id));
        $this->assertNull($this->menuPrice($this->waterA->id));
        $this->assertSame(5000, $this->menuPrice($this->noodleA->id, $this->storeA2));
        $this->api($this->ownerA, 'GET', 'store-prices', store: $this->storeA2)->assertOk()->assertJsonCount(0, 'data');

        $this->api($this->ownerA, 'DELETE', "products/{$this->noodleA->id}/store-price")->assertOk()->assertJsonPath('removed', 1);
        $this->assertSame(5000, $this->menuPrice($this->noodleA->id));
    }

    public function test_cashier_can_read_but_not_change_store_prices(): void
    {
        $this->api($this->cashierA1, 'GET', 'store-prices')->assertOk();
        $this->api($this->cashierA1, 'DELETE', "products/{$this->noodleA->id}/store-price")->assertForbidden();
    }

    public function test_modifier_group_update_checks_min_max_against_stored_values_and_options_can_be_added(): void
    {
        // กลุ่มเส้น: min=1 max=1 → ลด max เหลือ 0 ไม่ได้, เพิ่ม min เกิน max เดิมไม่ได้
        $this->api($this->ownerA, 'PATCH', "modifier-groups/{$this->noodleTypeA->id}", ['min_select' => 2])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['max_select']]]);

        $this->api($this->ownerA, 'PATCH', "modifier-groups/{$this->noodleTypeA->id}", ['name' => 'ชนิดเส้น', 'max_select' => 2])
            ->assertOk()->assertJsonPath('data.name', 'ชนิดเส้น')->assertJsonPath('data.max_select', 2);

        $this->api($this->ownerA, 'POST', "modifier-groups/{$this->noodleTypeA->id}/options", ['name' => 'วุ้นเส้น', 'price_delta_satang' => 500])
            ->assertCreated();

        // ตัวเลือกใหม่ต่อท้ายกลุ่มในเมนู POS
        $menu = $this->api($this->ownerA, 'GET', 'menu')->json('data');
        $group = collect($menu['uncategorized'])->firstWhere('id', $this->noodleA->id)['modifier_groups'][0];
        $this->assertSame('วุ้นเส้น', end($group['options'])['name']);
    }

    public function test_store_update_merges_settings_and_rejects_unknown_keys(): void
    {
        $this->api($this->ownerA, 'PATCH', "stores/{$this->storeA2->id}", ['settings' => ['kitchen_enabled' => false]])->assertOk();
        $this->api($this->ownerA, 'PATCH', "stores/{$this->storeA2->id}", ['name' => 'สาขาสอง', 'settings' => ['tax_mode' => 'inclusive']])
            ->assertOk()
            ->assertJsonPath('data.name', 'สาขาสอง')
            ->assertJsonPath('data.settings.kitchen_enabled', false)
            ->assertJsonPath('data.settings.tax_mode', 'inclusive');

        $this->api($this->ownerA, 'PATCH', "stores/{$this->storeA2->id}", ['settings' => ['promptpay_id' => '0812345678']])->assertStatus(422);
        $this->api($this->ownerA, 'PATCH', "stores/{$this->storeA2->id}", ['code' => 'NEW'])->assertOk();
        $this->assertDatabaseHas('stores', ['id' => $this->storeA2->id, 'code' => 'A2']);
    }

    public function test_cannot_deactivate_the_store_in_use_and_cashier_cannot_edit_stores(): void
    {
        $this->api($this->ownerA, 'PATCH', "stores/{$this->storeA1->id}", ['status' => 'inactive'])
            ->assertStatus(422)->assertJsonPath('error.code', 'cannot_deactivate_current_store');
        $this->api($this->ownerA, 'PATCH', "stores/{$this->storeA2->id}", ['status' => 'inactive'])->assertOk();

        $this->api($this->cashierA1, 'PATCH', "stores/{$this->storeA1->id}", ['name' => 'x'])->assertForbidden();
    }

    public function test_suspended_member_loses_tenant_access_until_reactivated(): void
    {
        $membershipId = collect($this->api($this->ownerA, 'GET', 'members')->json('data'))
            ->firstWhere('user.id', $this->cashierA1->id)['membership_id'];

        $this->api($this->ownerA, 'PATCH', "members/{$membershipId}", ['status' => 'suspended'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->api($this->cashierA1, 'GET', 'menu')->assertForbidden()->assertJsonPath('error.code', 'tenant_access_denied');

        $this->api($this->ownerA, 'PATCH', "members/{$membershipId}", ['status' => 'active'])->assertOk();
        $this->api($this->cashierA1, 'GET', 'menu')->assertOk();
    }

    public function test_owner_cannot_suspend_own_membership(): void
    {
        $own = collect($this->api($this->ownerA, 'GET', 'members')->json('data'))->firstWhere('user.id', $this->ownerA->id)['membership_id'];

        $this->api($this->ownerA, 'PATCH', "members/{$own}", ['status' => 'suspended'])
            ->assertStatus(422)->assertJsonPath('error.code', 'cannot_change_own_membership');
    }
}
