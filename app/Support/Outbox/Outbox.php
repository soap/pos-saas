<?php

namespace App\Support\Outbox;

/**
 * บันทึก outbox event ใน transaction เดียวกับ business change (spec §4.7)
 * worker (php artisan outbox:publish) จะส่งต่อเป็น Laravel event แบบ retry ได้
 */
class Outbox
{
    public static function record(?string $tenantId, string $aggregateType, string $aggregateId, string $eventType, array $payload = []): OutboxEvent
    {
        return OutboxEvent::create([
            'tenant_id' => $tenantId,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }
}
