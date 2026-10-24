<?php

namespace App\Modules\Payments\DTOs;

use DateTimeInterface;

final class PaymentRequest
{
    public function __construct(
        public readonly string $paymentId,
        public readonly int $attemptNumber,
        public readonly int $amountSatang,
        public readonly string $currency,
        public readonly string $description,
        public readonly DateTimeInterface $expiresAt,
    ) {}
}
