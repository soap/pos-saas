<?php

namespace App\Modules\Ordering\Models;

use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** โต๊ะของสาขา — มีออเดอร์ที่ยังเปิดได้ครั้งละ 1 ใบ (บังคับด้วย partial unique index) */
class DiningTable extends Model
{
    use BelongsToStore, HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'seats' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function openOrder(): HasOne
    {
        return $this->hasOne(Order::class)->whereIn('status', Order::OPEN_STATUSES);
    }
}
