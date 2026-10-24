<?php

namespace App\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentWebhookEvent extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'signature_verified_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
