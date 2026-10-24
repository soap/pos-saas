<?php

namespace App\Modules\Devices\Models;

use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PrintJob extends Model
{
    use BelongsToStore, HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'is_reprint' => 'boolean',
        'attempts' => 'integer',
        'requested_at' => 'datetime',
        'printed_at' => 'datetime',
    ];
}
