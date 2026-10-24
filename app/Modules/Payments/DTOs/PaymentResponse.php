<?php

namespace App\Modules\Payments\DTOs;

use DateTimeInterface;

final class PaymentResponse
{
    /** @param array $safePayload ข้อมูลที่บันทึกได้ (ห้ามมี secret/token) */
    public function __construct(
        public readonly string $providerReference,
        public readonly string $qrPayload,
        public readonly ?DateTimeInterface $expiresAt,
        public readonly array $safePayload = [],
    ) {}
}
