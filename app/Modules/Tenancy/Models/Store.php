<?php

namespace App\Modules\Tenancy\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'settings' => 'array',
    ];
}
