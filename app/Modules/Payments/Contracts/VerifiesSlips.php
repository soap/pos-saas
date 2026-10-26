<?php

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Models\PaymentAttempt;

/**
 * Provider แบบตรวจสลิป: ลูกค้าโอนเข้าบัญชีร้านโดยตรง แล้วแคชเชียร์สแกน QR บนสลิปส่งให้ provider ตรวจ
 * ผลตรวจกลับมาทาง webhook (หรือ getPaymentStatus เมื่อ webhook ไม่มา)
 */
interface VerifiesSlips
{
    /**
     * ส่งสลิปเข้าคิวตรวจ
     *
     * @return array<string, mixed> ข้อมูลที่บันทึกลง attempt.response_payload ได้ (ห้ามมี secret)
     *
     * @throws SlipRejectedException เมื่อ provider ปฏิเสธคำขอทันที (เช่น payload สลิปไม่ถูกต้อง)
     */
    public function submitSlip(PaymentAttempt $attempt, string $slipPayload, int $amountSatang): array;
}
