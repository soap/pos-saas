<?php

namespace App\Modules\Kitchen\Http\Resources;

use App\Modules\Kitchen\Models\KitchenTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin KitchenTicket */
class KitchenTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_number' => $this->whenLoaded('order', fn () => $this->order->order_number),
            'order_type' => $this->whenLoaded('order', fn () => $this->order->order_type),
            'table_label' => $this->whenLoaded('order', fn () => $this->order->table_label),
            'revision' => $this->revision,
            'is_addition' => $this->revision > 1,
            'status' => $this->status,
            'sent_at' => $this->sent_at?->toAtomString(),
            'acknowledged_at' => $this->acknowledged_at?->toAtomString(),
            'ready_at' => $this->ready_at?->toAtomString(),
            'served_at' => $this->served_at?->toAtomString(),
            'cancelled_at' => $this->cancelled_at?->toAtomString(),
            'cancel_reason' => $this->cancel_reason,
            'updated_at' => $this->updated_at?->toAtomString(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'order_item_id' => $i->order_item_id,
                'name' => $i->name_snapshot,
                'quantity' => $i->quantity,
                'modifiers' => $i->modifiers_snapshot,
                'notes' => $i->notes,
            ])->values()),
        ];
    }
}
