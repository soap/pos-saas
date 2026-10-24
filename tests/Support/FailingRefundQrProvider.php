<?php

namespace Tests\Support;

use App\Modules\Payments\DTOs\RefundResult;
use App\Modules\Payments\Providers\MockQrProvider;

/** Mock QR provider ที่ปฏิเสธการคืนเงินเสมอ — ใช้ทดสอบเส้นทาง refund ล้มเหลว */
class FailingRefundQrProvider extends MockQrProvider
{
    public function refund(string $providerReference, int $amountSatang, string $refundId): RefundResult
    {
        return new RefundResult('failed');
    }
}
