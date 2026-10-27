<?php

namespace App\Modules\Billing\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Subscription extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'current_period_end' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** จ่าย/ทดลองใช้ได้ถึงเมื่อไร — null = ไม่มีรอบเก็บเงิน (แพ็กเกจฟรี หรือ admin ให้ใช้โดยไม่กำหนดวันหมด) */
    public function paidThrough(): ?Carbon
    {
        return $this->status === 'trialing' ? $this->trial_ends_at : $this->current_period_end;
    }
}
