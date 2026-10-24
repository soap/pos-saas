<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;

/**
 * Global scope กลางสำหรับตารางที่มี tenant_id (spec §8: ลดการตกหล่นของ tenant scope)
 * - ถ้ามี tenant context → query ทุกครั้งถูกกรองด้วย tenant_id อัตโนมัติ
 * - ถ้าไม่มี context → throw TenantContextMissing (fail-closed) ยกเว้นอยู่ใน withoutTenancy()
 *   หรือ query ถอด scope เองด้วย withoutGlobalScope('tenant') / withoutGlobalScopes()
 * - ตอนสร้าง record ถ้ายังไม่ได้กำหนด tenant_id จะใช้ค่าจาก context
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $ctx = app(TenantContext::class);
            if ($ctx->isUnscoped()) {
                return;
            }
            if (! $ctx->hasTenant()) {
                throw TenantContextMissing::for($builder->getModel()::class);
            }
            $builder->where($builder->getModel()->qualifyColumn('tenant_id'), $ctx->tenantId());
        });

        static::creating(function ($model) {
            $ctx = app(TenantContext::class);
            if (empty($model->tenant_id) && $ctx->hasTenant()) {
                $model->tenant_id = $ctx->tenantId();
            }
        });
    }
}
