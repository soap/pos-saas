<?php

namespace App\Modules\Ordering\Http\Resources;

use App\Modules\Ordering\Models\DiningTable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DiningTable */
class DiningTableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'zone' => $this->zone,
            'seats' => $this->seats,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            // null = โต๊ะว่าง
            'open_order' => $this->whenLoaded('openOrder', fn () => $this->openOrder ? [
                'id' => $this->openOrder->id,
                'order_number' => $this->openOrder->order_number,
                'status' => $this->openOrder->status,
                'total_satang' => $this->openOrder->total_satang,
                'created_at' => $this->openOrder->created_at?->toAtomString(),
            ] : null),
        ];
    }
}
