<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Product;
use App\Support\Errors\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * รูปสินค้า (หลายรูปต่อสินค้า เลือกรูปที่แสดงได้)
 * ไฟล์อยู่บน disk private แยก tenant — client ได้เฉพาะ temporary URL
 */
class ProductImageService
{
    public function __construct(private AuditLogger $audit) {}

    public function add(Product $product, UploadedFile $file): Media
    {
        return DB::transaction(function () use ($product, $file) {
            // lock สินค้ากันอัปโหลดพร้อมกันจนเกินจำนวนที่กำหนด
            $product = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($product->getMedia(Product::IMAGES)->count() >= Product::MAX_IMAGES) {
                throw ApiException::conflict('too_many_images', 'สินค้าหนึ่งรายการมีรูปได้สูงสุด '.Product::MAX_IMAGES.' รูป');
            }

            $media = $product->addMedia($file)
                ->usingName(mb_substr(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 0, 255))
                ->usingFileName('original.'.$file->extension())
                ->toMediaCollection(Product::IMAGES);
            $this->audit->log('catalog.product_image_added', $product, ['media_id' => $media->id, 'size' => $media->size]);

            return $media;
        });
    }

    public function remove(Product $product, int $mediaId): void
    {
        $media = $this->find($product, $mediaId);
        // FK products.primary_media_id เป็น null on delete → กลับไปใช้รูปแรกที่เหลือ
        $media->delete();
        $this->audit->log('catalog.product_image_removed', $product, ['media_id' => $mediaId]);
    }

    public function setPrimary(Product $product, int $mediaId): void
    {
        $this->find($product, $mediaId);
        $before = $product->primary_media_id;
        $product->update(['primary_media_id' => $mediaId]);
        $this->audit->log('catalog.product_primary_image_set', $product, ['before' => $before, 'after' => $mediaId]);
    }

    /**
     * @return list<array{id: int, name: string, is_primary: bool, thumb_url: string, preview_url: string}>
     */
    public function present(Product $product): array
    {
        $primaryId = $product->displayImage()?->id;

        return $product->getMedia(Product::IMAGES)->map(fn (Media $media) => [
            'id' => $media->id,
            'name' => $media->name,
            'is_primary' => $media->id === $primaryId,
            'thumb_url' => $this->url($media, 'thumb'),
            'preview_url' => $this->url($media, 'preview'),
        ])->values()->all();
    }

    /** URL รูปที่แสดงขนาดเล็ก สำหรับเมนู POS */
    public function displayThumbUrl(Product $product): ?string
    {
        $media = $product->displayImage();

        return $media ? $this->url($media, 'thumb') : null;
    }

    /**
     * signed URL แบบ relative (ใช้ได้ทั้ง path/subdomain ของร้าน) เปิดผ่าน MediaController
     * หมดอายุต้นวันถัดไปอีกวัน (อายุ 24–48 ชม.) — ภายในวันเดียวกันได้ URL เดิม browser จึง cache รูปได้
     * และจอ POS ที่เปิดทั้งวันไม่เจอรูปหมดอายุ
     */
    private function url(Media $media, string $conversion): string
    {
        // worker ยังย่อรูปไม่เสร็จ → ใช้ไฟล์ต้นฉบับไปก่อน (โหลดเมนูครั้งถัดไปจะได้รูปย่อ)
        if (! $media->hasGeneratedConversion($conversion)) {
            $conversion = '';
        }

        return URL::temporarySignedRoute('media.show', Carbon::now()->startOfDay()->addDays(2), [
            'uuid' => $media->uuid, 'conversion' => $conversion,
        ], absolute: false);
    }

    private function find(Product $product, int $mediaId): Media
    {
        return $product->getMedia(Product::IMAGES)->firstWhere('id', $mediaId)
            ?? throw ApiException::notFound('image_not_found', 'ไม่พบรูปของสินค้านี้');
    }
}
