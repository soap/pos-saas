<?php

namespace App\Modules\Billing\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPayment extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'amount_satang' => 'integer',
        'plan_price_snapshot_satang' => 'integer',
        'billing_period_start' => 'datetime',
        'billing_period_end' => 'datetime',
        'paid_at' => 'datetime',
    ];
}
