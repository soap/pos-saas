<?php

namespace App\Modules\Ordering\Http\Resources;

use App\Modules\Kitchen\Http\Resources\KitchenTicketResource;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Services\OrderService;
use App\Modules\Payments\Http\Resources\PaymentResource;
use App\Support\Money\Satang;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $orders = app(OrderService::class);
        $withDetail = $this->relationLoaded('items');

        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'business_date' => $this->business_date?->format('Y-m-d'),
            'order_number' => $this->order_number,
            'order_type' => $this->order_type,
            'table_label' => $this->table_label,
            'status' => $this->status,
            'subtotal_satang' => $this->subtotal_satang,
            'discount_satang' => $this->discount_satang,
            'tax_satang' => $this->tax_satang,
            'total_satang' => $this->total_satang,
            'paid_satang' => $this->when($withDetail, fn () => Satang::of($orders->netPaid($this->resource))),
            'balance_due_satang' => $this->when($withDetail, fn () => Satang::of($orders->balanceDue($this->resource))),
            'refund_due_satang' => $this->when($withDetail, fn () => Satang::of($orders->refundDue($this->resource))),
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toAtomString(),
            'completed_at' => $this->completed_at?->toAtomString(),
            'cancelled_at' => $this->cancelled_at?->toAtomString(),
            'cancel_reason' => $this->cancel_reason,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'name' => $i->product_name_snapshot,
                'sku' => $i->sku_snapshot,
                'unit_price_satang' => $i->unit_price_satang,
                'modifiers_total_satang' => $i->modifiers_total_satang,
                'quantity' => $i->quantity,
                'discount_satang' => $i->discount_satang,
                'tax_satang' => $i->tax_satang,
                'line_total_satang' => $i->line_total_satang,
                'notes' => $i->notes,
                'status' => $i->status,
                'modifiers' => $i->modifiers->map(fn ($m) => [
                    'modifier_option_id' => $m->modifier_option_id,
                    'group' => $m->group_name_snapshot,
                    'name' => $m->name_snapshot,
                    'price_delta_satang' => $m->price_delta_satang,
                    'quantity' => $m->quantity,
                ])->values(),
            ])->values()),
            'kitchen_tickets' => KitchenTicketResource::collection($this->whenLoaded('kitchenTickets')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
