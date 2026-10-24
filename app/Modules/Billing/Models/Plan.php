<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'price_satang' => 'integer',
        'limits' => 'array',
        'is_active' => 'boolean',
    ];
}
