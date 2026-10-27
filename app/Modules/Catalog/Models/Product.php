<?php

namespace App\Modules\Catalog\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Product extends Model implements HasMedia
{
    use BelongsToTenant, HasUuids, InteractsWithMedia, SoftDeletes;

    public const IMAGES = 'images';

    public const MAX_IMAGES = 10;

    protected $guarded = ['id'];

    protected $casts = [
        'base_price_satang' => 'integer',
        'sort_order' => 'integer',
        'primary_media_id' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class, 'product_modifier_groups')
            ->withPivot(['tenant_id', 'sort_order'])
            ->orderBy('product_modifier_groups.sort_order');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::IMAGES)
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    /** ย่อรูปผ่าน queue หลัง commit — QueueTenancy พา tenant context ไปให้ worker */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 320, 320)
            ->format('webp')
            ->performOnCollections(self::IMAGES);

        $this->addMediaConversion('preview')
            ->fit(Fit::Max, 1200, 1200)
            ->format('webp')
            ->performOnCollections(self::IMAGES);
    }

    /** รูปที่ตั้งให้แสดง — ถ้าไม่ได้ตั้ง (หรือรูปถูกลบ) ใช้รูปแรกตามลำดับ */
    public function displayImage(): ?Media
    {
        $images = $this->getMedia(self::IMAGES);

        return $images->firstWhere('id', $this->primary_media_id) ?? $images->first();
    }
}
