<?php

namespace App\Modules\Payments\Models;

use App\Modules\Ordering\Models\Order;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use BelongsToStore, HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'amount_satang' => 'integer',
        'tendered_satang' => 'integer',
        'change_satang' => 'integer',
        'refunded_satang' => 'integer',
        'paid_at' => 'datetime',
    ];

    public const SETTLED_STATUSES = ['succeeded', 'partially_refunded', 'refunded'];

    public const IN_FLIGHT_STATUSES = ['pending', 'processing'];

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class)->orderBy('attempt_number');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('created_at');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
