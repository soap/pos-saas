<?php

use App\Modules\Billing\Services\BillingService;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Services\PaymentProviderRegistry;
use App\Modules\Payments\Services\WebhookService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

/**
 * จำลองลูกค้าสแกนจ่าย QR (Mock provider) — ส่ง webhook ที่ sign ถูกต้องเข้า WebhookService
 *   php artisan mock-qr:pay <payment_id|provider_reference> [--status=succeeded]
 */
Artisan::command('mock-qr:pay {reference} {--status=succeeded}', function (string $reference, PaymentProviderRegistry $registry, WebhookService $webhooks) {
    if (app()->isProduction()) {
        $this->error('ใช้ได้เฉพาะ dev/test');

        return 1;
    }
    $attempt = PaymentAttempt::query()->withoutGlobalScopes()
        ->where(fn ($q) => $q->where('provider_reference', $reference)->orWhere('payment_id', Str::isUuid($reference) ? $reference : null))
        ->whereNotNull('provider_reference')->latest('attempt_number')->first();
    if (! $attempt) {
        $this->error('ไม่พบ QR payment');

        return 1;
    }
    $hook = $registry->get('mock')->simulateWebhook($attempt->provider_reference, $this->option('status'));
    $result = $webhooks->handle('mock', $hook['headers'], $hook['body']);
    $this->info("webhook result: {$result}");

    return 0;
})->purpose('จำลอง webhook ชำระเงิน QR สำเร็จ (mock provider)');

Artisan::command('idempotency:prune', function () {
    $n = DB::table('idempotency_keys')->where('expires_at', '<', now())->delete();
    $this->info("deleted {$n}");
})->purpose('ลบ idempotency keys ที่หมดอายุ');

Artisan::command('billing:cycle', function (BillingService $billing) {
    $result = $billing->runCycle();
    $this->info("invoiced={$result['invoiced']} past_due={$result['past_due']}");
})->purpose('ออกใบแจ้งหนี้ค่าแพ็กเกจรอบถัดไป และเปลี่ยนร้านที่เลยวันจ่ายเป็น past_due');

Schedule::command('outbox:publish')->everyMinute()->withoutOverlapping();
Schedule::command('billing:cycle')->hourly()->withoutOverlapping();
Schedule::command('idempotency:prune')->hourly();
