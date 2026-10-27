<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Errors\ApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ตรวจสิทธิ์ใช้งานตาม plan/subscription และ tenant_features (spec §4.6)
 */
class SubscriptionGate
{
    public const USABLE_STATUSES = ['trialing', 'active', 'past_due'];

    public function current(Tenant $tenant): ?Subscription
    {
        return Subscription::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', '<>', 'cancelled')
            ->with('plan')
            ->first();
    }

    public function ensureActive(Tenant $tenant): void
    {
        if (! config('pos.enforce_subscription')) {
            return;
        }
        if (! $this->isUsable($this->current($tenant))) {
            throw new ApiException(402, 'subscription_inactive', 'แพ็กเกจของร้านหมดอายุหรือยังไม่เปิดใช้งาน');
        }
    }

    /** ใช้งานได้ถ้าสถานะยังมีผล และยังไม่เลยวันที่จ่ายถึง + ระยะผ่อนผัน (ครบรอบแล้วยังไม่จ่าย = past_due ใช้ต่อได้ grace_days วัน) */
    public function isUsable(?Subscription $sub): bool
    {
        if (! $sub || ! in_array($sub->status, self::USABLE_STATUSES, true)) {
            return false;
        }
        $graceEndsAt = $this->graceEndsAt($sub);

        return $graceEndsAt === null || $graceEndsAt->isFuture();
    }

    /** วันที่จะถูกระงับถ้ายังไม่จ่าย — null = ไม่มีรอบเก็บเงิน */
    public function graceEndsAt(Subscription $sub): ?Carbon
    {
        return $sub->paidThrough()?->copy()->addDays((int) config('pos.billing.grace_days'));
    }

    /** ค่า limit เช่น max_stores: tenant_features override > plan.limits; null = ไม่จำกัด */
    public function limit(Tenant $tenant, string $key): ?int
    {
        $override = DB::table('tenant_features')->where('tenant_id', $tenant->id)->where('feature_key', $key)->first();
        if ($override) {
            return $override->enabled ? ($override->limit_value === null ? null : (int) $override->limit_value) : 0;
        }
        $limits = $this->current($tenant)?->plan?->limits ?? [];

        return isset($limits[$key]) ? (int) $limits[$key] : null;
    }

    public function ensureWithinLimit(Tenant $tenant, string $key, int $currentCount): void
    {
        $limit = $this->limit($tenant, $key);
        if ($limit !== null && $currentCount >= $limit) {
            throw ApiException::unprocessable('plan_limit_reached', "เกินจำนวนที่แพ็กเกจกำหนด ({$key} = {$limit})");
        }
    }
}
