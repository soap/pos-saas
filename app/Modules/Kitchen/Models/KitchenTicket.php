<?php

namespace App\Modules\Kitchen\Models;

use App\Modules\Ordering\Models\Order;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KitchenTicket extends Model
{
    use BelongsToStore, HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'revision' => 'integer',
        'sent_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'ready_at' => 'datetime',
        'served_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(KitchenTicketItem::class)->orderBy('created_at')->orderBy('id');
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class, 'kitchen_station_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
