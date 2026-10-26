<?php

namespace App\Modules\Kitchen\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Product;
use App\Modules\Kitchen\Models\KitchenStation;
use App\Modules\Kitchen\Models\KitchenTicket;
use App\Modules\Kitchen\Models\KitchenTicketItem;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Models\OrderItem;
use App\Modules\Ordering\Services\OrderService;
use App\Support\Errors\ApiException;
use App\Support\Money\Satang;
use App\Support\Outbox\Outbox;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kitchen ticket + transition rules (spec §4.3.1, ภาคผนวก D2)
 *
 *   queued ──acknowledge──▶ preparing ──ready──▶ ready ──served──▶ served
 *     └───────────────ready───────────────┘
 *   queued/preparing/ready ──cancel(kitchen.void)──▶ cancelled
 */
class KitchenService
{
    private const TRANSITIONS = [
        'acknowledge' => ['from' => ['queued'], 'to' => 'preparing'],
        'ready' => ['from' => ['queued', 'preparing'], 'to' => 'ready'],
        'served' => ['from' => ['ready'], 'to' => 'served'],
        'cancel' => ['from' => ['queued', 'preparing', 'ready'], 'to' => 'cancelled'],
    ];

    public function __construct(private OrderService $orders, private AuditLogger $audit) {}

    /**
     * ส่งรายการที่ยังไม่เคยส่งเข้าครัวเป็น revision ใหม่ (spec §6 ข้อ 8) — แตกเป็น ticket ละ station (ภาคผนวก D5)
     * retry ซ้ำจะไม่สร้าง revision ใหม่ เพราะไม่มีรายการค้างส่งแล้ว
     *
     * @return Collection<int, KitchenTicket> ครัวหลัก (ไม่ระบุ station) ก่อน แล้วตามลำดับ station
     */
    public function sendToKitchen(Order $order, string $actorId): Collection
    {
        return DB::transaction(function () use ($order, $actorId) {
            $order = $this->orders->lock($order);
            if (! $order->isOpen()) {
                throw ApiException::conflict('order_not_open', "ออเดอร์สถานะ {$order->status} ส่งครัวไม่ได้");
            }

            $items = $this->orders->unsentItemsQuery($order)->with('modifiers')->orderBy('created_at')->get();
            if ($items->isEmpty()) {
                throw ApiException::conflict('nothing_to_send', 'ไม่มีรายการใหม่ที่ต้องส่งเข้าครัว');
            }

            $revision = (int) KitchenTicket::query()->where('order_id', $order->id)->max('revision') + 1;
            $tickets = $this->groupByStation($items)->map(function (Collection $stationItems, string $stationKey) use ($order, $revision) {
                $ticket = KitchenTicket::create([
                    'tenant_id' => $order->tenant_id,
                    'store_id' => $order->store_id,
                    'order_id' => $order->id,
                    'kitchen_station_id' => $stationKey === '' ? null : $stationKey,
                    'revision' => $revision,
                    'status' => 'queued',
                    'sent_at' => now(),
                ]);
                /** @var OrderItem $item */
                foreach ($stationItems as $item) {
                    KitchenTicketItem::create([
                        'tenant_id' => $order->tenant_id,
                        'store_id' => $order->store_id,
                        'kitchen_ticket_id' => $ticket->id,
                        'order_item_id' => $item->id,
                        'name_snapshot' => $item->product_name_snapshot,
                        'quantity' => $item->quantity,
                        'modifiers_snapshot' => $item->modifiers->map(fn ($m) => [
                            'group' => $m->group_name_snapshot, 'name' => $m->name_snapshot, 'quantity' => $m->quantity,
                        ])->values()->all(),
                        'notes' => $item->notes,
                    ]);
                }

                return $ticket;
            })->values();

            $this->orders->event($order, 'kitchen.ticket_sent', null, null, $actorId, [
                'ticket_ids' => $tickets->pluck('id')->all(), 'revision' => $revision,
            ]);
            $this->orders->syncStatus($order, $actorId);
            foreach ($tickets as $ticket) {
                Outbox::record($order->tenant_id, 'kitchen_ticket', $ticket->id, 'kitchen.ticket_created', [
                    'store_id' => $order->store_id, 'order_id' => $order->id, 'revision' => $revision, 'kitchen_station_id' => $ticket->kitchen_station_id,
                ]);
            }

            return $tickets->each->load(['items', 'station']);
        });
    }

    /**
     * จัดกลุ่มรายการตาม station ปัจจุบันของสินค้า (key '' = ครัวหลัก)
     * station ที่ปิดใช้งานหรือสินค้าที่ถูกลบ → ครัวหลัก เพื่อไม่ให้งานไปค้างที่จอที่ไม่มีคนดู
     *
     * @param  Collection<int, OrderItem>  $items
     * @return Collection<string, Collection<int, OrderItem>>
     */
    private function groupByStation(Collection $items): Collection
    {
        $stationOf = Product::query()->withTrashed()->whereIn('id', $items->pluck('product_id')->filter())->pluck('kitchen_station_id', 'id');
        $activeStations = KitchenStation::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->pluck('id');

        $groups = $items->groupBy(function (OrderItem $item) use ($stationOf, $activeStations) {
            $stationId = $stationOf[$item->product_id] ?? null;

            return $stationId !== null && $activeStations->contains($stationId) ? $stationId : '';
        });

        return $groups->sortBy(fn ($group, string $key) => $key === '' ? -1 : $activeStations->search($key));
    }

    /**
     * เปลี่ยนสถานะ ticket — ถ้าอยู่ในสถานะปลายทางแล้วถือว่าสำเร็จ (idempotent)
     * ถ้าเป็น transition ที่ไม่อนุญาต/stale → 409
     */
    public function transition(KitchenTicket $ticket, string $action, string $actorId, ?string $reason = null): KitchenTicket
    {
        $rule = self::TRANSITIONS[$action] ?? throw ApiException::badRequest('unknown_action', 'action ไม่ถูกต้อง');

        return DB::transaction(function () use ($ticket, $action, $rule, $actorId, $reason) {
            // lock order ก่อน ticket เสมอ (ลำดับเดียวกับ sendToKitchen ป้องกัน deadlock)
            $order = $this->orders->lock(Order::query()->findOrFail($ticket->order_id));
            $ticket = KitchenTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            if ($ticket->status === $rule['to']) {
                return $ticket->load('items');
            }
            if (! in_array($ticket->status, $rule['from'], true)) {
                throw ApiException::conflict('invalid_transition', "เปลี่ยนจาก {$ticket->status} ด้วย {$action} ไม่ได้", [
                    'current_status' => $ticket->status,
                ]);
            }

            $from = $ticket->status;
            $ticket->status = $rule['to'];
            match ($rule['to']) {
                'preparing' => $ticket->acknowledged_at = now(),
                'ready' => $ticket->ready_at = now(),
                'served' => $ticket->served_at = now(),
                'cancelled' => [$ticket->cancelled_at = now(), $ticket->cancel_reason = $reason],
            };
            if ($rule['to'] === 'ready' && ! $ticket->acknowledged_at) {
                $ticket->acknowledged_at = now();
            }
            $ticket->save();

            if ($action === 'cancel') {
                // void รายการที่อยู่ใน ticket นี้ และคำนวณยอดใหม่
                $itemIds = $ticket->items()->pluck('order_item_id');
                OrderItem::query()->whereIn('id', $itemIds)->update(['status' => 'voided', 'updated_at' => now()]);
                $this->orders->recalculate($order);
                $refundDue = Satang::of($this->orders->refundDue($order));
                $this->audit->log('kitchen.ticket_cancelled', $ticket, [
                    'reason' => $reason, 'from_status' => $from, 'order_id' => $order->id, 'refund_due_satang' => $refundDue,
                ]);
                if ($refundDue > 0) {
                    // ลูกค้าจ่ายรายการที่ถูกยกเลิกไปแล้ว → แจ้งหน้าร้านให้คืนเงิน (ออเดอร์จะยังไม่ completed จนกว่าจะคืน)
                    $this->orders->event($order, 'order.refund_due', null, null, $actorId, ['amount' => $refundDue, 'ticket_id' => $ticket->id]);
                }
            }

            $this->orders->event($order, 'kitchen.ticket_'.$rule['to'], $from, $rule['to'], $actorId, [
                'ticket_id' => $ticket->id, 'revision' => $ticket->revision, 'reason' => $reason,
                'device' => request()?->header('X-Device-Code'),
            ]);
            $this->orders->syncStatus($order, $actorId);
            Outbox::record($ticket->tenant_id, 'kitchen_ticket', $ticket->id, 'kitchen.ticket_'.$rule['to'], [
                'store_id' => $ticket->store_id, 'order_id' => $order->id,
            ]);

            return $ticket->load('items');
        });
    }
}
