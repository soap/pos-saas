<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ราคา/การขายได้ของสินค้าในแต่ละสาขา
 * - ราคา = store_products.price_satang ถ้ามี มิฉะนั้น products.base_price_satang
 * - ขายได้ = product.status=active และ (ไม่มี row store_products หรือ is_available=true)
 */
class MenuService
{
    public function __construct(private ProductImageService $images) {}

    /** @return array{price:int, available:bool, tax_profile:string} */
    public function storePricing(Store $store, Product $product): array
    {
        $row = DB::table('store_products')
            ->where('tenant_id', $store->tenant_id)
            ->where('store_id', $store->id)
            ->where('product_id', $product->id)
            ->first();

        return [
            'price' => $row ? (int) $row->price_satang : $product->base_price_satang,
            'available' => $product->status === 'active' && (! $row || $row->is_available),
            'tax_profile' => $row->tax_profile ?? 'none',
        ];
    }

    /** เมนูสำหรับหน้าจอ POS ของสาขา */
    public function menuFor(Store $store): array
    {
        $overrides = DB::table('store_products')
            ->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)
            ->get()->keyBy('product_id');

        $products = Product::query()
            ->where('status', 'active')
            ->with(['media', 'modifierGroups.options' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->filter(fn (Product $p) => ! isset($overrides[$p->id]) || $overrides[$p->id]->is_available)
            ->map(fn (Product $p) => $this->presentProduct($p, $overrides[$p->id] ?? null));

        $categories = Category::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();

        return [
            'categories' => $categories->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'products' => $products->where('category_id', $c->id)->values(),
            ])->values(),
            'uncategorized' => $products->whereNull('category_id')->values(),
        ];
    }

    private function presentProduct(Product $p, ?object $override): array
    {
        return [
            'id' => $p->id,
            'category_id' => $p->category_id,
            'sku' => $p->sku,
            'name' => $p->name,
            'description' => $p->description,
            'price_satang' => $override ? (int) $override->price_satang : $p->base_price_satang,
            'image_url' => $this->images->displayThumbUrl($p),
            'modifier_groups' => $this->presentGroups($p->modifierGroups),
        ];
    }

    private function presentGroups(Collection $groups): array
    {
        return $groups->map(fn ($g) => [
            'id' => $g->id,
            'name' => $g->name,
            'min_select' => $g->min_select,
            'max_select' => $g->max_select,
            'is_required' => $g->is_required,
            'options' => $g->options->map(fn ($o) => [
                'id' => $o->id, 'name' => $o->name, 'price_delta_satang' => $o->price_delta_satang,
            ])->values(),
        ])->values()->all();
    }
}
