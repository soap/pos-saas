<?php

namespace App\Modules\Payments\Models;

use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentAttempt extends Model
{
    use BelongsToStore, HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'attempt_number' => 'integer',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'expires_at' => 'datetime',
    ];
}
