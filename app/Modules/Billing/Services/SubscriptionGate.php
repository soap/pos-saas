<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Errors\ApiException;
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
        $sub = $this->current($tenant);
        $usable = $sub && in_array($sub->status, self::USABLE_STATUSES, true)
            && ! ($sub->status === 'trialing' && $sub->trial_ends_at && $sub->trial_ends_at->isPast());
        if (! $usable) {
            throw new ApiException(402, 'subscription_inactive', 'แพ็กเกจของร้านหมดอายุหรือยังไม่เปิดใช้งาน');
        }
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
