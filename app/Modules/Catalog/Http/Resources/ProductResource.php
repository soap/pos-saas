<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description,
            'base_price_satang' => $this->base_price_satang,
            'status' => $this->status,
            'sort_order' => $this->sort_order,
            'kitchen_station_id' => $this->kitchen_station_id,
            'modifier_group_ids' => $this->whenLoaded('modifierGroups', fn () => $this->modifierGroups->pluck('id')),
            'updated_at' => $this->updated_at?->toAtomString(),
        ];
    }
}
