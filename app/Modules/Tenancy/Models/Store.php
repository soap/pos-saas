<?php

namespace App\Modules\Tenancy\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    public const TAX_MODES = ['none', 'inclusive'];

    protected $casts = [
        'settings' => 'array',
    ];

    /** none | inclusive — ตั้งต่อสาขาด้วย settings.tax_mode มิฉะนั้นใช้ config pos.tax_mode */
    public function taxMode(): string
    {
        return $this->settings['tax_mode'] ?? config('pos.tax_mode');
    }
}
