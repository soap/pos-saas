<?php

namespace App\Support\Media;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

/**
 * แยกไฟล์ราย tenant: tenants/{tenant_id}/media/{media uuid}/
 * ใช้ uuid แทน id เพื่อไม่ให้เดา path ของไฟล์อื่นได้
 */
class TenantPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return "tenants/{$media->getAttribute('tenant_id')}/media/{$media->uuid}/";
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->getPath($media).'conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->getPath($media).'responsive-images/';
    }
}
