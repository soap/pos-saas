<?php

namespace App\Http\Controllers;

use App\Support\Media\TenantMedia;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * เปิดไฟล์ media บน disk private ด้วย signed URL (ออกโดย ProductImageService)
 * ลายเซ็นคือสิทธิ์เข้าถึง — <img> ส่ง Bearer token ไม่ได้ และ uuid ระบุไฟล์ได้เพียงไฟล์เดียว
 */
class MediaController extends Controller
{
    public function __invoke(TenantContext $ctx, string $uuid, string $conversion = ''): StreamedResponse
    {
        $media = $ctx->withoutTenancy(fn () => TenantMedia::query()->where('uuid', $uuid)->firstOrFail());
        abort_if($conversion !== '' && ! $media->hasGeneratedConversion($conversion), 404);
        $path = $media->getPathRelativeToRoot($conversion);
        $disk = Storage::disk($conversion === '' ? $media->disk : $media->conversions_disk);
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $media->file_name, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
