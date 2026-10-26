<?php

namespace App\Modules\Kitchen\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** จุดทำอาหารในครัว (เช่น เครื่องดื่ม/ย่าง) — สินค้าที่ไม่ระบุ station ไปที่ครัวหลัก */
class KitchenStation extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
