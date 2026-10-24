<?php

namespace App\Modules\Ordering\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OrderItemModifier extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'price_delta_satang' => 'integer',
        'quantity' => 'integer',
    ];
}
