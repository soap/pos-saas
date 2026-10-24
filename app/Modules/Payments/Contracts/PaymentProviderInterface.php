<?php

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\DTOs\PaymentRequest;
use App\Modules\Payments\DTOs\PaymentResponse;
use App\Modules\Payments\DTOs\ProviderPaymentStatus;
use App\Modules\Payments\DTOs\VerifiedWebhook;
use App\Modules\Payments\Providers\InvalidWebhookException;

/**
 * Payment Adapter ที่ไม่ผูก provider (spec §4.4.1)
 * - createPayment() สร้าง QR → payment เป็น processing เท่านั้น ไม่ใช่ succeeded
 * - verifyWebhook() ต้องตรวจ signature กับ raw body และ provider account
 */
interface PaymentProviderInterface
{
    public function code(): string;

    public function createPayment(PaymentRequest $request): PaymentResponse;

    public function getPaymentStatus(string $providerReference): ProviderPaymentStatus;

    /** @throws InvalidWebhookException */
    public function verifyWebhook(array $headers, string $rawBody): VerifiedWebhook;
}
