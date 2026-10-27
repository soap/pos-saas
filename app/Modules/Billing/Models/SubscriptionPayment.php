<?php

namespace App\Modules\Billing\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ใบแจ้งหนี้ค่าแพ็กเกจต่อรอบ: pending → paid (หรือ void เมื่อ admin ยกเลิก) */
class SubscriptionPayment extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['provider_charge_ref'];

    protected $casts = [
        'amount_satang' => 'integer',
        'plan_price_snapshot_satang' => 'integer',
        'billing_period_start' => 'datetime',
        'billing_period_end' => 'datetime',
        'paid_at' => 'datetime',
        'charge_expires_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** มี QR ที่ยังสแกนจ่ายได้ — ไม่ต้องสร้าง charge ใหม่ */
    public function hasLiveCharge(): bool
    {
        return $this->provider_charge_ref !== null && $this->charge_expires_at?->isFuture() === true;
    }
}
