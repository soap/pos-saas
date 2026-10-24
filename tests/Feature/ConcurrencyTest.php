<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\ConcurrentRequests;
use Tests\Support\PosWorld;
use Tests\TestCase;

/** spec §10 Order number, Idempotency, Refund, Cashier — request พร้อมกันจริงจากหลายโปรเซส */
class ConcurrencyTest extends TestCase
{
    use ConcurrentRequests, PosWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    public function refreshDatabase(): void
    {
        $this->useCommittedDatabase();
    }

    public function test_simultaneous_orders_get_unique_sequential_numbers(): void
    {
        $responses = $this->concurrently($this->cashierA1, array_fill(0, 6, [
            'method' => 'POST',
            'uri' => 'orders',
            'body' => ['order_type' => 'takeaway', 'items' => [$this->noodleIntent()]],
        ]));

        $this->assertSame(array_fill(0, 6, 201), $this->statuses($responses));
        $numbers = array_map(fn (array $response): int => $response['body']['data']['order_number'], $responses);
        sort($numbers);
        $this->assertSame([1, 2, 3, 4, 5, 6], $numbers);
        $this->assertSame(7, (int) DB::table('store_order_counters')->where('store_id', $this->storeA1->id)->value('next_number'));
    }

    public function test_simultaneous_requests_with_same_idempotency_key_create_one_order(): void
    {
        $responses = $this->concurrently($this->cashierA1, array_fill(0, 4, [
            'method' => 'POST',
            'uri' => 'orders',
            'body' => ['order_type' => 'takeaway', 'items' => [$this->noodleIntent()]],
            'idempotency_key' => 'same-key-0001',
        ]));

        $created = array_filter($responses, fn (array $response): bool => $response['status'] === 201);
        $rejected = array_filter($responses, fn (array $response): bool => $response['status'] === 409);
        $this->assertNotEmpty($created);
        $this->assertCount(4, $created + $rejected);
        $this->assertCount(1, array_unique(array_map(fn (array $response): string => $response['body']['data']['id'], $created)));
        foreach ($rejected as $response) {
            $this->assertSame('idempotency_in_progress', $response['body']['error']['code']);
        }
        $this->assertSame(1, DB::table('orders')->count());
    }

    public function test_simultaneous_refunds_never_exceed_paid_amount(): void
    {
        $this->openShift($this->cashierA1);
        $order = $this->createOrder();
        $paymentId = $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/payments", ['method' => 'cash'])->assertCreated()->json('data.id');

        $responses = $this->concurrently($this->ownerA, array_fill(0, 4, [
            'method' => 'POST',
            'uri' => "payments/{$paymentId}/refunds",
            'body' => ['amount_satang' => 3000, 'reason' => 'ของหมด'],
        ]));

        $this->assertSame([201, 201, 201, 422], $this->statuses($responses));
        $rejected = array_values(array_filter($responses, fn (array $response): bool => $response['status'] === 422));
        $this->assertSame('refund_exceeds_payment', $rejected[0]['body']['error']['code']);
        $this->assertSame(9000, (int) DB::table('refunds')->where('payment_id', $paymentId)->where('status', 'succeeded')->sum('amount_satang'));
        $this->assertSame(9000, (int) DB::table('payments')->where('id', $paymentId)->value('refunded_satang'));
        $this->assertSame(3, DB::table('cash_movements')->where('movement_type', 'refund')->count());
    }

    public function test_simultaneous_shift_closes_close_once(): void
    {
        $shift = $this->openShift($this->cashierA1, 50000);

        $responses = $this->concurrently($this->cashierA1, array_fill(0, 3, [
            'method' => 'POST',
            'uri' => "cashier-shifts/{$shift['id']}/close",
            'body' => ['counted_cash_satang' => 50000],
        ]));

        $this->assertSame([200, 409, 409], $this->statuses($responses));
        $this->assertDatabaseHas('cashier_shifts', ['id' => $shift['id'], 'status' => 'closed', 'expected_cash_satang' => 50000, 'variance_satang' => 0]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'cashier.shift_closed')->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'cashier.shift_closed')->count());
    }
}
