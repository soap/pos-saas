<?php

namespace App\Modules\Payments\DTOs;

final class VerifiedWebhook
{
    /** @param string $status pending|succeeded|failed|expired|rejected — ดู ProviderPaymentStatus */
    public function __construct(
        public readonly string $externalEventId,
        public readonly string $accountKey,
        public readonly string $providerReference,
        public readonly string $status,
        public readonly array $payload,
        public readonly ?string $transactionRef = null,
        public readonly ?string $reason = null,
    ) {}
}
