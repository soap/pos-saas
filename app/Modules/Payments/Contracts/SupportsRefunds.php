<?php

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\DTOs\RefundResult;

interface SupportsRefunds
{
    public function refund(string $providerReference, int $amountSatang, string $refundId): RefundResult;
}
