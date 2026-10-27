<?php

namespace App\Support\Media;

use App\Support\Tenancy\BelongsToTenant;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Media ที่ผูก tenant — query ถูกกรองด้วย tenant_id เหมือนตารางอื่น (fail-closed เมื่อไม่มี context)
 * tenant_id ยึดตาม model เจ้าของไฟล์ เพื่อให้ path ไฟล์ตรงกับ tenant ของข้อมูลเสมอ
 */
class TenantMedia extends Media
{
    use BelongsToTenant;

    protected $table = 'media';

    protected static function booted(): void
    {
        static::creating(function (self $media): void {
            $ownerTenantId = $media->model?->getAttribute('tenant_id');
            if ($ownerTenantId !== null) {
                $media->tenant_id = $ownerTenantId;
            }
        });
    }
}
