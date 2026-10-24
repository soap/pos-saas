<?php

namespace App\Modules\Payments\DTOs;

final class VerifiedWebhook
{
    /** @param string $status pending|succeeded|failed|expired */
    public function __construct(
        public readonly string $externalEventId,
        public readonly string $accountKey,
        public readonly string $providerReference,
        public readonly string $status,
        public readonly array $payload,
    ) {}
}
