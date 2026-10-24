<?php

namespace App\Modules\Payments\DTOs;

final class ProviderPaymentStatus
{
    /** @param string $status pending|succeeded|failed|expired */
    public function __construct(
        public readonly string $status,
        public readonly array $safePayload = [],
    ) {}
}
