<?php

namespace App\Modules\Payments\DTOs;

final class RefundResult
{
    public function __construct(
        public readonly string $status, // succeeded|failed
        public readonly ?string $providerReference = null,
    ) {}
}
