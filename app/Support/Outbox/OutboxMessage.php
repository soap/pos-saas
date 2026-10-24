<?php

namespace App\Support\Outbox;

/**
 * Laravel event ที่ถูก dispatch โดย outbox publisher
 * listener ต้องออกแบบให้ idempotent ด้วย $outboxId (อาจถูกส่งซ้ำเมื่อ retry)
 */
class OutboxMessage
{
    public function __construct(
        public readonly string $outboxId,
        public readonly ?string $tenantId,
        public readonly string $aggregateType,
        public readonly string $aggregateId,
        public readonly string $eventType,
        public readonly array $payload,
    ) {}
}
