<?php

namespace App\Support\Tenancy;

use LogicException;

/**
 * query model ที่มี tenant_id โดยไม่มี tenant context = bug ของโค้ด (fail-closed)
 * ถ้าตั้งใจข้าม tenant ให้ใช้ TenantContext::withoutTenancy() หรือ runAs()
 */
class TenantContextMissing extends LogicException
{
    public static function for(string $model): self
    {
        return new self("ไม่มี tenant context ขณะ query {$model} — ใช้ TenantContext::runAs() หรือ withoutTenancy() ให้ชัดเจน");
    }
}
