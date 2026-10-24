<?php

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'cashier_shift_id' => $this->cashier_shift_id,
            'method' => $this->method,
            'amount_satang' => $this->amount_satang,
            'tendered_satang' => $this->tendered_satang,
            'change_satang' => $this->change_satang,
            'refunded_satang' => $this->refunded_satang,
            'currency' => $this->currency,
            'status' => $this->status,
            'paid_at' => $this->paid_at?->toAtomString(),
            'created_at' => $this->created_at?->toAtomString(),
            'refunds' => $this->whenLoaded('refunds', fn () => $this->refunds->map(fn ($r) => [
                'id' => $r->id,
                'amount_satang' => $r->amount_satang,
                'status' => $r->status,
                'reason' => $r->reason,
                'succeeded_at' => $r->succeeded_at?->toAtomString(),
            ])->values()),
        ];
    }
}
