<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Tests\Support\PosWorld;
use Tests\TestCase;

/** ภาคผนวก D7 — KDS acceptance */
class KitchenTest extends TestCase
{
    use PosWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    public function test_send_to_kitchen_creates_revision_and_retry_does_not_duplicate(): void
    {
        $order = $this->createOrder();
        $t1 = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/send-to-kitchen", idemKey: 'send-0001')->assertCreated();
        $this->assertSame(1, $t1->json('data.revision'));
        $this->assertFalse($t1->json('data.is_addition'));

        // retry ด้วย key เดิม → response เดิม
        $replay = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/send-to-kitchen", idemKey: 'send-0001')->assertCreated();
        $this->assertSame($t1->json('data.id'), $replay->json('data.id'));
        // กดซ้ำด้วย key ใหม่ → ไม่มีรายการใหม่ ไม่สร้าง revision
        $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/send-to-kitchen")
            ->assertStatus(409)->assertJsonPath('error.code', 'nothing_to_send');

        $this->api($this->ownerA, 'GET', "orders/{$order['id']}")->assertJsonPath('data.status', 'placed');
    }

    public function test_added_items_go_out_as_new_revision_marked_as_addition(): void
    {
        $order = $this->createOrder();
        $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/send-to-kitchen")->assertCreated();
        $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/items", ['items' => [['product_id' => $this->waterA->id, 'quantity' => 2]]])->assertOk();
        $t2 = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/send-to-kitchen")->assertCreated();

        $this->assertSame(2, $t2->json('data.revision'));
        $this->assertTrue($t2->json('data.is_addition'));
        $this->assertCount(1, $t2->json('data.items'));
        $this->assertSame('น้ำเปล่า', $t2->json('data.items.0.name'));
    }

    public function test_transitions_are_idempotent_and_reject_stale_changes(): void
    {
        $order = $this->createOrder();
        $ticket = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/send-to-kitchen")->json('data');

        $this->api($this->kitchenA1, 'POST', "kitchen/tickets/{$ticket['id']}/acknowledge")->assertOk()->assertJsonPath('data.status', 'preparing');
        // จอที่สองกดรับงานซ้ำ → สำเร็จแบบ idempotent
        $this->api($this->kitchenA1, 'POST', "kitchen/tickets/{$ticket['id']}/acknowledge")->assertOk()->assertJsonPath('data.status', 'preparing');
        $this->api($this->ownerA, 'GET', "orders/{$order['id']}")->assertJsonPath('data.status', 'preparing');

        $this->api($this->kitchenA1, 'POST', "kitchen/tickets/{$ticket['id']}/ready")->assertOk()->assertJsonPath('data.status', 'ready');
        // จอที่ยังเห็นสถานะเก่า กด served ไม่ได้ถ้ายังไม่ ready / ย้อนกลับไม่ได้
        $this->api($this->kitchenA1, 'POST', "kitchen/tickets/{$ticket['id']}/acknowledge")
            ->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
        $this->api($this->kitchenA1, 'POST', "kitchen/tickets/{$ticket['id']}/served")->assertOk();
        $this->api($this->ownerA, 'GET', "orders/{$order['id']}")->assertJsonPath('data.status', 'served');

        $this->assertDatabaseHas('order_events', ['order_id' => $order['id'], 'event_type' => 'kitchen.ticket_preparing', 'actor_id' => $this->kitchenA1->id]);
    }

    public function test_polling_with_cursor_returns_changes_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 05:00:00', 'UTC'));
        $order = $this->createOrder();
        $ticket = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/send-to-kitchen")->json('data');

        $poll = $this->api($this->kitchenA1, 'GET', 'kitchen/tickets')->assertOk();
        $this->assertCount(1, $poll->json('data'));
        $cursor = $poll->json('server_time');

        Carbon::setTestNow(Carbon::parse('2026-10-21 05:00:10', 'UTC'));
        $this->assertCount(0, $this->api($this->kitchenA1, 'GET', 'kitchen/tickets?updated_after='.urlencode(Carbon::parse($cursor)->addSecond()->toAtomString()))->json('data'));

        $this->api($this->kitchenA1, 'POST', "kitchen/tickets/{$ticket['id']}/acknowledge")->assertOk();
        $delta = $this->api($this->kitchenA1, 'GET', 'kitchen/tickets?updated_after='.urlencode($cursor))->json('data');
        $this->assertSame('preparing', $delta[0]['status']);
        Carbon::setTestNow();
    }

    public function test_polling_is_paginated_with_keyset_cursor(): void
    {
        $ids = [];
        foreach (range(1, 3) as $_) {
            $order = $this->createOrder();
            $ids[] = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/send-to-kitchen")->json('data.id');
        }

        $page1 = $this->api($this->kitchenA1, 'GET', 'kitchen/tickets?limit=2')->assertOk();
        $this->assertCount(2, $page1->json('data'));
        $this->assertTrue($page1->json('has_more'));

        $page2 = $this->api($this->kitchenA1, 'GET', 'kitchen/tickets?limit=2&cursor='.$page1->json('next_cursor'))->assertOk();
        $this->assertCount(1, $page2->json('data'));
        $this->assertFalse($page2->json('has_more'));
        $this->assertNull($page2->json('next_cursor'));

        $seen = array_merge(array_column($page1->json('data'), 'id'), array_column($page2->json('data'), 'id'));
        $this->assertEqualsCanonicalizing($ids, $seen);
    }

    public function test_polling_rejects_unknown_status_and_bad_cursor(): void
    {
        $this->api($this->kitchenA1, 'GET', 'kitchen/tickets?status=queued,bogus')
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->api($this->kitchenA1, 'GET', 'kitchen/tickets?cursor=not-a-cursor')
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_cancel_after_payment_requires_refund_before_completion(): void
    {
        $this->openShift($this->cashierA1);
        $order = $this->createOrder(); // 10000
        $t1 = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/send-to-kitchen")->json('data');
        $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/items", ['items' => [['product_id' => $this->waterA->id, 'quantity' => 1]]])->assertOk();
        $t2 = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/send-to-kitchen")->json('data');
        $paymentId = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'cash'])
            ->assertCreated()->assertJsonPath('data.amount_satang', 11000)->json('data.id');

        // ครัวยกเลิกน้ำหลังลูกค้าจ่ายแล้ว → รับเงินเกิน 1000
        $this->api($this->ownerA, 'POST', "kitchen/tickets/{$t2['id']}/cancel", ['reason' => 'น้ำหมด'])->assertOk();
        $this->api($this->ownerA, 'GET', "orders/{$order['id']}")
            ->assertJsonPath('data.total_satang', 10000)->assertJsonPath('data.refund_due_satang', 1000);
        $this->assertDatabaseHas('order_events', ['order_id' => $order['id'], 'event_type' => 'order.refund_due']);

        foreach (['acknowledge', 'ready', 'served'] as $action) {
            $this->api($this->kitchenA1, 'POST', "kitchen/tickets/{$t1['id']}/{$action}")->assertOk();
        }
        // เสิร์ฟครบแต่ยังค้างคืนเงิน → ยังไม่ completed
        $this->api($this->ownerA, 'GET', "orders/{$order['id']}")->assertJsonPath('data.status', 'served');

        $this->api($this->ownerA, 'POST', "payments/{$paymentId}/refunds", ['amount_satang' => 1000, 'reason' => 'น้ำหมด'])->assertCreated();
        $this->api($this->ownerA, 'GET', "orders/{$order['id']}")
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.refund_due_satang', 0);
    }

    public function test_cancel_ticket_requires_permission_and_voids_items(): void
    {
        $order = $this->createOrder();
        $ticket = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/send-to-kitchen")->json('data');

        $this->api($this->kitchenA1, 'POST', "kitchen/tickets/{$ticket['id']}/cancel", ['reason' => 'หมดวัตถุดิบ'])->assertForbidden();
        $this->api($this->ownerA, 'POST', "kitchen/tickets/{$ticket['id']}/cancel", ['reason' => 'หมดวัตถุดิบ'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->api($this->ownerA, 'GET', "orders/{$order['id']}")->assertJsonPath('data.total_satang', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'kitchen.ticket_cancelled', 'subject_id' => $ticket['id']]);
    }
}
