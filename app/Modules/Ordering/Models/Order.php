<?php

namespace App\Modules\Ordering\Models;

use App\Modules\Kitchen\Models\KitchenTicket;
use App\Modules\Payments\Models\Payment;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use BelongsToStore, HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'business_date' => 'date:Y-m-d',
        'order_number' => 'integer',
        'subtotal_satang' => 'integer',
        'discount_satang' => 'integer',
        'tax_satang' => 'integer',
        'total_satang' => 'integer',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public const OPEN_STATUSES = ['draft', 'placed', 'preparing', 'ready', 'served'];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('created_at')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderBy('created_at');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('created_at');
    }

    public function kitchenTickets(): HasMany
    {
        return $this->hasMany(KitchenTicket::class)->orderBy('revision');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
