<?php

namespace App\Modules\Ordering\Models;

use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    use BelongsToStore, HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'unit_price_satang' => 'integer',
        'modifiers_total_satang' => 'integer',
        'quantity' => 'integer',
        'discount_satang' => 'integer',
        'tax_satang' => 'integer',
        'line_total_satang' => 'integer',
    ];

    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderItemModifier::class);
    }
}
