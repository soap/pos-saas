<?php

namespace App\Modules\Ordering\Services;

use Akaunting\Money\Money;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Kitchen\Models\KitchenTicket;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Models\OrderEvent;
use App\Modules\Ordering\Models\OrderItem;
use App\Modules\Ordering\Models\OrderItemModifier;
use App\Modules\Payments\Models\Payment;
use App\Modules\Tenancy\Models\Store;
use App\Support\Errors\ApiException;
use App\Support\Money\Satang;
use App\Support\Outbox\Outbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(
        private OrderLineBuilder $lines,
        private AuditLogger $audit,
    ) {}

    /**
     * สร้างออเดอร์พร้อมรายการใน transaction เดียว (spec §6 ข้อ 1-4)
     */
    public function create(Store $store, string $actorId, array $data): Order
    {
        return DB::transaction(function () use ($store, $actorId, $data) {
            $businessDate = $this->businessDate($store);
            $number = $this->nextOrderNumber($store, $businessDate);

            $order = Order::create([
                'tenant_id' => $store->tenant_id,
                'store_id' => $store->id,
                'business_date' => $businessDate,
                'order_number' => $number,
                'order_type' => $data['order_type'],
                'table_label' => $data['table_label'] ?? null,
                'status' => 'draft',
                'subtotal_satang' => 0,
                'discount_satang' => 0,
                'tax_satang' => 0,
                'total_satang' => 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);

            foreach ($data['items'] ?? [] as $i => $intent) {
                $this->insertLine($store, $order, $intent, $i);
            }
            $this->recalculate($order);
            $this->event($order, 'order.created', null, 'draft', $actorId, ['item_count' => count($data['items'] ?? [])]);
            Outbox::record($order->tenant_id, 'order', $order->id, 'order.created', ['store_id' => $store->id, 'order_number' => $number]);

            return $order;
        });
    }

    public function addItems(Store $store, Order $order, string $actorId, array $items): Order
    {
        return DB::transaction(function () use ($store, $order, $actorId, $items) {
            $order = $this->lock($order);
            $this->ensureOpen($order);
            foreach ($items as $i => $intent) {
                $this->insertLine($store, $order, $intent, $i);
            }
            $this->recalculate($order);
            $this->event($order, 'order.items_added', null, null, $actorId, ['count' => count($items)]);

            return $order;
        });
    }

    /** แก้จำนวน — ทำได้เฉพาะรายการที่ยังไม่ส่งเข้าครัว (ส่งแล้วต้องทำ revision/void แยก) */
    public function updateItemQuantity(Order $order, string $itemId, int $quantity, string $actorId): Order
    {
        return DB::transaction(function () use ($order, $itemId, $quantity, $actorId) {
            $order = $this->lock($order);
            $this->ensureOpen($order);
            $item = $this->unsentItem($order, $itemId);
            $old = $item->quantity;
            $perUnit = Satang::money($item->unit_price_satang)->add(Satang::money($item->modifiers_total_satang));
            $lineTotal = $perUnit->multiply($quantity)
                ->subtract(Satang::money($item->discount_satang))
                ->add(Satang::money($item->tax_satang));
            $item->update([
                'quantity' => $quantity,
                'line_total_satang' => Satang::of($lineTotal),
            ]);
            $this->recalculate($order);
            $this->event($order, 'order.item_quantity_changed', null, null, $actorId, ['item_id' => $item->id, 'from' => $old, 'to' => $quantity]);

            return $order;
        });
    }

    public function removeItem(Order $order, string $itemId, string $actorId): Order
    {
        return DB::transaction(function () use ($order, $itemId, $actorId) {
            $order = $this->lock($order);
            $this->ensureOpen($order);
            $item = $this->unsentItem($order, $itemId);
            $item->update(['status' => 'voided']);
            $this->recalculate($order);
            $this->event($order, 'order.item_removed', null, null, $actorId, ['item_id' => $item->id, 'name' => $item->product_name_snapshot]);

            return $order;
        });
    }

    public function applyDiscount(Order $order, int $discount, ?string $reason, string $actorId): Order
    {
        return DB::transaction(function () use ($order, $discount, $reason, $actorId) {
            $order = $this->lock($order);
            $this->ensureOpen($order);
            if (Satang::money($discount)->greaterThan(Satang::money($order->subtotal_satang))) {
                throw ApiException::unprocessable('discount_exceeds_subtotal', 'ส่วนลดมากกว่ายอดรวม');
            }
            $old = $order->discount_satang;
            $order->discount_satang = $discount;
            $this->recalculate($order);
            $this->event($order, 'order.discount_applied', null, null, $actorId, ['from' => $old, 'to' => $discount, 'reason' => $reason]);
            $this->audit->log('order.discount', $order, ['from' => $old, 'to' => $discount, 'reason' => $reason]);

            return $order;
        });
    }

    public function cancel(Order $order, string $reason, string $actorId): Order
    {
        return DB::transaction(function () use ($order, $reason, $actorId) {
            $order = $this->lock($order);
            if (! $order->isOpen()) {
                throw ApiException::conflict('order_not_cancellable', "ออเดอร์สถานะ {$order->status} ยกเลิกไม่ได้");
            }
            $hasMoney = Payment::query()->where('order_id', $order->id)
                ->whereIn('status', ['pending', 'processing', 'succeeded', 'partially_refunded'])->exists();
            if ($hasMoney) {
                throw ApiException::conflict('order_has_payments', 'มีการชำระเงินค้างอยู่ ต้องคืนเงิน/ยกเลิกการชำระก่อน');
            }

            KitchenTicket::query()->where('order_id', $order->id)->whereIn('status', ['queued', 'preparing', 'ready'])
                ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'order_cancelled', 'updated_at' => now()]);

            $from = $order->status;
            $order->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            $this->event($order, 'order.cancelled', $from, 'cancelled', $actorId, ['reason' => $reason]);
            $this->audit->log('order.cancelled', $order, ['reason' => $reason, 'from_status' => $from]);
            Outbox::record($order->tenant_id, 'order', $order->id, 'order.cancelled', ['store_id' => $order->store_id]);

            return $order;
        });
    }

    // ------------------------------------------------------------------
    // สถานะ / การชำระเงิน
    // ------------------------------------------------------------------

    /** ยอดที่ชำระสำเร็จสุทธิ (หัก refund ที่สำเร็จแล้ว) */
    public function netPaid(Order $order): Money
    {
        $row = Payment::query()->where('order_id', $order->id)
            ->whereIn('status', Payment::SETTLED_STATUSES)
            ->selectRaw('COALESCE(SUM(amount_satang),0) AS paid, COALESCE(SUM(refunded_satang),0) AS refunded')
            ->first();

        return Satang::money((int) $row->paid)->subtract(Satang::money((int) $row->refunded));
    }

    public function inFlightAmount(Order $order): Money
    {
        return Satang::money((int) Payment::query()->where('order_id', $order->id)
            ->whereIn('status', Payment::IN_FLIGHT_STATUSES)->sum('amount_satang'));
    }

    public function balanceDue(Order $order): Money
    {
        return Satang::max(Satang::zero(), Satang::money($order->total_satang)->subtract($this->netPaid($order)));
    }

    /** ยอดที่รับเกิน (เช่น void รายการ/ให้ส่วนลดหลังชำระแล้ว) — ต้อง refund ก่อนออเดอร์จะปิดได้ */
    public function refundDue(Order $order): Money
    {
        return Satang::max(Satang::zero(), $this->netPaid($order)->subtract(Satang::money($order->total_satang)));
    }

    /**
     * คำนวณสถานะออเดอร์จาก kitchen tickets และการชำระเงิน
     * ต้องเรียกภายใน transaction ที่ lock order แล้ว
     */
    public function syncStatus(Order $order, ?string $actorId = null): Order
    {
        if (! $order->isOpen()) {
            return $order;
        }
        $from = $order->status;
        $active = KitchenTicket::query()->where('order_id', $order->id)->where('status', '<>', 'cancelled')->pluck('status');

        $to = $from;
        if ($active->isNotEmpty()) {
            if ($active->contains('preparing')) {
                $to = 'preparing';
            } elseif ($active->contains('queued')) {
                $to = 'placed';
            } elseif ($active->every(fn ($s) => $s === 'served')) {
                $to = 'served';
            } else {
                $to = 'ready';
            }
        }

        // ถ้าสาขาปิดการใช้ครัว (settings.kitchen_enabled=false) ไม่ต้องรอส่งครัวก่อนปิดออเดอร์
        $kitchenEnabled = (bool) (Store::query()->find($order->store_id)?->settings['kitchen_enabled'] ?? true);
        $hasUnsent = $kitchenEnabled && $this->unsentItemsQuery($order)->exists();
        // ต้องตรงยอดพอดี — ถ้ารับเกิน (refundDue > 0) ต้องคืนเงินก่อน ไม่ปิดออเดอร์ทั้งที่ค้างคืนลูกค้า
        $total = Satang::money($order->total_satang);
        $fullyPaid = $total->isPositive() && $this->netPaid($order)->equals($total);
        $kitchenDone = $active->isEmpty() || $active->every(fn ($s) => $s === 'served');
        if ($fullyPaid && $kitchenDone && ! $hasUnsent && $order->items()->where('status', 'active')->exists()) {
            $to = 'completed';
        }

        if ($to !== $from) {
            $order->status = $to;
            if ($to === 'completed') {
                $order->completed_at = now();
            }
            $order->save();
            $this->event($order, 'order.status_changed', $from, $to, $actorId);
            if ($to === 'completed') {
                Outbox::record($order->tenant_id, 'order', $order->id, 'order.completed', ['store_id' => $order->store_id]);
            }
        }

        return $order;
    }

    public function unsentItemsQuery(Order $order)
    {
        return OrderItem::query()->where('order_id', $order->id)->where('status', 'active')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('kitchen_ticket_items as kti')
                    ->join('kitchen_tickets as kt', 'kt.id', '=', 'kti.kitchen_ticket_id')
                    ->whereColumn('kti.order_item_id', 'order_items.id')
                    ->where('kt.status', '<>', 'cancelled');
            });
    }

    public function lock(Order $order): Order
    {
        return Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    public function event(Order $order, string $type, ?string $from, ?string $to, ?string $actorId, array $payload = []): void
    {
        OrderEvent::create([
            'tenant_id' => $order->tenant_id,
            'store_id' => $order->store_id,
            'order_id' => $order->id,
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actorId,
            'payload' => $payload ?: (object) [],
            'created_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------

    public function businessDate(Store $store): string
    {
        return Carbon::now($store->timezone ?: 'Asia/Bangkok')->toDateString();
    }

    /**
     * เลขออเดอร์ต่อสาขาต่อวันทำการ: lock counter row ด้วย SELECT ... FOR UPDATE (spec §6 ข้อ 2)
     * ห้ามใช้ MAX(order_number)+1
     */
    public function nextOrderNumber(Store $store, string $businessDate): int
    {
        DB::table('store_order_counters')->insertOrIgnore([
            'tenant_id' => $store->tenant_id,
            'store_id' => $store->id,
            'business_date' => $businessDate,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $counter = DB::table('store_order_counters')
            ->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)->where('business_date', $businessDate)
            ->lockForUpdate()->first();

        DB::table('store_order_counters')
            ->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)->where('business_date', $businessDate)
            ->update(['next_number' => $counter->next_number + 1, 'updated_at' => now()]);

        return (int) $counter->next_number;
    }

    private function insertLine(Store $store, Order $order, array $intent, int $index): OrderItem
    {
        $line = $this->lines->build($store, $intent, $index);
        $item = OrderItem::create($line['item'] + [
            'tenant_id' => $order->tenant_id,
            'store_id' => $order->store_id,
            'order_id' => $order->id,
        ]);
        foreach ($line['modifiers'] as $m) {
            OrderItemModifier::create($m + ['tenant_id' => $order->tenant_id, 'order_item_id' => $item->id]);
        }

        return $item;
    }

    public function recalculate(Order $order): void
    {
        $sums = OrderItem::query()->where('order_id', $order->id)->where('status', 'active')
            ->selectRaw('COALESCE(SUM(line_total_satang - tax_satang + discount_satang),0) AS gross, COALESCE(SUM(discount_satang),0) AS line_discount, COALESCE(SUM(tax_satang),0) AS tax')
            ->first();

        $subtotal = Satang::money((int) $sums->gross);
        $orderDiscount = Satang::min(Satang::money($order->discount_satang), $subtotal);
        $tax = Satang::money((int) $sums->tax);
        $total = $subtotal->subtract($orderDiscount)->subtract(Satang::money((int) $sums->line_discount))->add($tax);

        $order->subtotal_satang = Satang::of($subtotal);
        $order->discount_satang = Satang::of($orderDiscount);
        $order->tax_satang = Satang::of($tax);
        $order->total_satang = Satang::of($total);
        $order->save();
    }

    private function ensureOpen(Order $order): void
    {
        if (! $order->isOpen()) {
            throw ApiException::conflict('order_not_open', "ออเดอร์สถานะ {$order->status} แก้ไขไม่ได้");
        }
    }

    private function unsentItem(Order $order, string $itemId): OrderItem
    {
        $item = OrderItem::query()->where('order_id', $order->id)->where('status', 'active')->find($itemId)
            ?? throw ApiException::notFound('order_item_not_found', 'ไม่พบรายการ');
        if (! $this->unsentItemsQuery($order)->whereKey($item->id)->exists()) {
            throw ApiException::conflict('item_already_sent', 'รายการนี้ส่งเข้าครัวแล้ว แก้ไขไม่ได้');
        }

        return $item;
    }
}
