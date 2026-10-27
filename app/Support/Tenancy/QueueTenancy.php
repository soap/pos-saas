<?php

namespace App\Support\Tenancy;

use App\Modules\Tenancy\Models\Store;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Queue\Queue;

/**
 * พา tenant context ข้ามไปยัง queued job (แนวเดียวกับ QueueTenancyBootstrapper ของ stancl/tenancy)
 *
 * - ตอน dispatch: แนบ tenant_id/store_id ของ context ปัจจุบันลง payload
 * - JobProcessing: ตั้ง context จาก payload ก่อน Laravel unserialize job
 *   (SerializesModels ดึง model กลับภายใต้ global scope ของ tenant นั้น)
 * - JobAttempted (ยิงใน finally ทั้ง worker และ sync): คืน context เดิม
 *   sync queue รันใน request เดิม — ถ้าล้างทิ้งแทนการคืน request จะเสีย tenant
 *
 * job ไม่มี tenant_id = งานระดับ central → context ว่าง (query model ของ tenant จะ fail-closed)
 * tenant/store ใน payload หาไม่เจอ → throw ให้ job ล้ม แทนที่จะรันโดยไม่มี scope
 */
class QueueTenancy
{
    /** @var list<array<int, mixed>> TenantContext::snapshot() ก่อนเริ่มแต่ละ job (sync job ซ้อนกันได้) */
    private array $previous = [];

    public function register(Dispatcher $events): void
    {
        Queue::createPayloadUsing(function (): array {
            $ctx = app(TenantContext::class);

            return $ctx->hasTenant() ? ['tenant_id' => $ctx->tenantId(), 'store_id' => $ctx->storeId()] : [];
        });

        $events->listen(JobProcessing::class, fn (JobProcessing $event) => $this->begin($event->job));
        $events->listen(JobAttempted::class, fn () => $this->end());
        // queue:retry unserialize job เพื่อต่ออายุ retryUntil — ต้องมี context ก่อน (ไม่มี event จบ; คำสั่งตั้งใหม่ทุก job)
        $events->listen(JobRetryRequested::class, fn (JobRetryRequested $event) => $this->enter($event->payload()));
    }

    private function begin(Job $job): void
    {
        // เก็บก่อนทำอะไรที่ throw ได้ — JobAttempted ยิงเสมอแม้ listener นี้ล้ม จึงต้องมีของให้คืนทุกครั้ง
        $this->previous[] = app(TenantContext::class)->snapshot();
        $this->enter($job->payload());
    }

    private function end(): void
    {
        $previous = array_pop($this->previous);
        if ($previous !== null) {
            app(TenantContext::class)->restore($previous);
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function enter(array $payload): void
    {
        $ctx = app(TenantContext::class);
        $tenantId = $payload['tenant_id'] ?? null;
        if ($tenantId === null) {
            $ctx->enterJob(null);

            return;
        }

        [$tenant, $store] = $ctx->withoutTenancy(fn () => [
            Tenant::query()->findOrFail($tenantId),
            isset($payload['store_id']) ? Store::query()->where('tenant_id', $tenantId)->findOrFail($payload['store_id']) : null,
        ]);
        $ctx->enterJob($tenant, $store);
    }
}
