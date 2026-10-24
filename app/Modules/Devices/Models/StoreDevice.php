<?php

namespace App\Modules\Devices\Models;

use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StoreDevice extends Model
{
    use BelongsToStore, HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'config' => 'array',
        'last_seen_at' => 'datetime',
    ];
}
