<?php

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

class Tenant extends Model
{
    use HasUuids;

    /** slug ใช้เป็น subdomain/path ได้ (DNS label: a-z 0-9 -, ไม่ขึ้นต้น/ลงท้ายด้วย -) */
    public const SLUG_PATTERN = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /** @return array<int,mixed> */
    public static function slugRules(): array
    {
        return [
            'required', 'string', 'max:63', 'regex:/^'.self::SLUG_PATTERN.'$/',
            Rule::notIn(config('pos.tenancy.reserved_slugs')), 'unique:tenants,slug',
        ];
    }

    protected $guarded = ['id'];

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }
}
