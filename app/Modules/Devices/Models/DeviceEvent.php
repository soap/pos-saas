<?php

namespace App\Modules\Devices\Models;

use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DeviceEvent extends Model
{
    use BelongsToStore, HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];
}
