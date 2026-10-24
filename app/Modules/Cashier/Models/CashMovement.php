<?php

namespace App\Modules\Cashier\Models;

use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashMovement extends Model
{
    use BelongsToStore, HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'amount_satang' => 'integer',
        'created_at' => 'datetime',
    ];
}
