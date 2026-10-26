<?php

namespace App\Modules\Payments\DTOs;

final class ProviderPaymentStatus
{
    /**
     * @param  string  $status  pending|succeeded|failed|expired|rejected (rejected = สลิปไม่ผ่าน, payment ยังรอสลิปใหม่ได้)
     * @param  ?string  $transactionRef  เลขอ้างอิงธุรกรรมจริง (เช่น transRef ของสลิป)
     * @param  ?string  $reason  เหตุผลเมื่อ rejected
     */
    public function __construct(
        public readonly string $status,
        public readonly array $safePayload = [],
        public readonly ?string $transactionRef = null,
        public readonly ?string $reason = null,
    ) {}
}
