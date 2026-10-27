<?php

namespace App\Support\Realtime;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * สัญญาณ realtime ว่าออเดอร์ในสาขามีการเปลี่ยนแปลง (ภาคผนวก D3 Phase 2)
 *
 * - ส่งแค่ id + ชนิด event ไม่ส่งข้อมูลออเดอร์ — client ดึงรายละเอียดล่าสุดจาก API เอง (ตรวจสิทธิ์ที่ API เหมือนเดิม)
 * - ส่งหลัง commit และผ่าน queue: ถ้า transaction rollback จะไม่มีสัญญาณ, ถ้า Reverb/worker ล่ม request ไม่พัง
 *   และหน้าจอยังมี polling สำรอง
 */
class StoreActivity implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $storeId,
        public readonly string $orderId,
        public readonly string $eventType,
        public readonly ?string $paymentId = null,
    ) {}

    public static function channelName(string $storeId): string
    {
        return "store.{$storeId}";
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(self::channelName($this->storeId));
    }

    public function broadcastAs(): string
    {
        return 'order.changed';
    }

    /** @return array{order_id: string, event_type: string, payment_id: ?string} */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'event_type' => $this->eventType,
            'payment_id' => $this->paymentId,
        ];
    }
}
