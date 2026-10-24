<?php

namespace App\Modules\Kitchen\Models;

use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class KitchenTicketItem extends Model
{
    use BelongsToStore, HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'integer',
        'modifiers_snapshot' => 'array',
    ];
}
