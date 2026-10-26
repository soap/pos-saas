<?php

namespace App\Modules\Payments\DTOs;

use DateTimeInterface;

final class PaymentResponse
{
    /**
     * มี qrPayload (EMV string ให้ client render เอง) หรือ qrImageUrl (รูป QR จาก provider) อย่างน้อยหนึ่งอย่าง
     *
     * @param  array  $safePayload  ข้อมูลที่บันทึกได้ (ห้ามมี secret/token)
     */
    public function __construct(
        public readonly string $providerReference,
        public readonly ?string $qrPayload,
        public readonly ?DateTimeInterface $expiresAt,
        public readonly array $safePayload = [],
        public readonly ?string $qrImageUrl = null,
    ) {}
}
