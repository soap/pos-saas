<?php

namespace App\Modules\Cashier\Models;

use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashierShift extends Model
{
    use BelongsToStore, HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'opening_float_satang' => 'integer',
        'expected_cash_satang' => 'integer',
        'counted_cash_satang' => 'integer',
        'variance_satang' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class, 'shift_id')->orderBy('created_at');
    }
}
