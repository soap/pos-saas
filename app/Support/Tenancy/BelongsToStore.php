<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;

/**
 * Global scope สำหรับตารางระดับสาขา (มีทั้ง tenant_id และ store_id)
 */
trait BelongsToStore
{
    use BelongsToTenant;

    public static function bootBelongsToStore(): void
    {
        static::addGlobalScope('store', function (Builder $builder) {
            $ctx = app(TenantContext::class);
            // route ระดับ tenant (ไม่มี store) เห็นได้ทุกสาขาของ tenant — tenant scope ยังบังคับอยู่
            if ($ctx->hasStore() && ! $ctx->isUnscoped()) {
                $builder->where($builder->getModel()->qualifyColumn('store_id'), $ctx->storeId());
            }
        });

        static::creating(function ($model) {
            $ctx = app(TenantContext::class);
            if (empty($model->store_id) && $ctx->hasStore()) {
                $model->store_id = $ctx->storeId();
            }
        });
    }
}
