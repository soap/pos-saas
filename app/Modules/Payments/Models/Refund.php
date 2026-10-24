<?php

namespace App\Modules\Payments\Models;

use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    use BelongsToStore, HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'amount_satang' => 'integer',
        'succeeded_at' => 'datetime',
    ];
}
