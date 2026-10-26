<?php

namespace App\Modules\Ordering\Services;

use App\Modules\Catalog\Models\ModifierOption;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\MenuService;
use App\Modules\Tenancy\Models\Store;
use App\Support\Errors\ApiException;
use App\Support\Money\Satang;

/**
 * แปลง "intent" จาก client (product_id, quantity, modifier_option_ids, notes)
 * เป็นรายการที่ราคา/ชื่อมาจากฐานข้อมูลทั้งหมด (spec §6 ข้อ 3) — ไม่รับราคาจาก client
 */
class OrderLineBuilder
{
    public function __construct(private MenuService $menu) {}

    /**
     * @param  array{product_id:string, quantity:int, modifier_option_ids?:array<string>, notes?:?string}  $intent
     * @return array{item: array<string,mixed>, modifiers: array<int,array<string,mixed>>}
     */
    public function build(Store $store, array $intent, int $index = 0): array
    {
        $product = Product::query()->with('modifierGroups')->find($intent['product_id']);
        if (! $product) {
            throw ApiException::unprocessable('product_not_found', 'ไม่พบสินค้า', ["items.{$index}.product_id" => $intent['product_id']]);
        }
        $pricing = $this->menu->storePricing($store, $product);
        if (! $pricing['available']) {
            throw ApiException::unprocessable('product_unavailable', "สินค้า {$product->name} ไม่พร้อมขาย", ["items.{$index}.product_id" => $product->id]);
        }

        $optionIds = array_values(array_unique($intent['modifier_option_ids'] ?? []));
        $options = $optionIds
            ? ModifierOption::query()->with('group')->whereIn('id', $optionIds)->where('is_active', true)->get()->keyBy('id')
            : collect();
        if ($options->count() !== count($optionIds)) {
            throw ApiException::unprocessable('modifier_invalid', 'ตัวเลือกเพิ่มเติมไม่ถูกต้องหรือไม่พร้อมใช้งาน', ["items.{$index}.modifier_option_ids" => $optionIds]);
        }

        // ตรวจว่าตัวเลือกทั้งหมดอยู่ในกลุ่มที่ผูกกับสินค้านี้ และจำนวนที่เลือกอยู่ในช่วง min/max
        $groups = $product->modifierGroups->keyBy('id');
        $countByGroup = [];
        foreach ($options as $opt) {
            if (! $groups->has($opt->modifier_group_id)) {
                throw ApiException::unprocessable('modifier_not_allowed', "ตัวเลือก {$opt->name} ใช้กับสินค้านี้ไม่ได้");
            }
            $countByGroup[$opt->modifier_group_id] = ($countByGroup[$opt->modifier_group_id] ?? 0) + 1;
        }
        foreach ($groups as $g) {
            $n = $countByGroup[$g->id] ?? 0;
            $min = $g->is_required ? max(1, $g->min_select) : $g->min_select;
            if ($n < $min || $n > $g->max_select) {
                throw ApiException::unprocessable('modifier_selection_invalid', "กลุ่ม {$g->name} ต้องเลือก {$min}-{$g->max_select} รายการ", ['group_id' => $g->id]);
            }
        }

        $modifiersTotal = Satang::sum($options->pluck('price_delta_satang'));
        $qty = (int) $intent['quantity'];
        $unit = Satang::money($pricing['price']);
        $perUnit = $unit->add($modifiersTotal);
        if ($perUnit->isNegative()) {
            throw ApiException::unprocessable('line_negative', 'ราคาต่อหน่วยหลังรวมตัวเลือกติดลบไม่ได้');
        }

        // ราคารวม VAT แล้ว (spec ภาคผนวก B2) → VAT คิดระดับบิลหลังหักส่วนลดใน OrderService::recalculate
        $lineTotal = $perUnit->multiply($qty);

        return [
            'item' => [
                'product_id' => $product->id,
                'product_name_snapshot' => $product->name,
                'sku_snapshot' => $product->sku,
                'unit_price_satang' => Satang::of($unit),
                'modifiers_total_satang' => Satang::of($modifiersTotal),
                'quantity' => $qty,
                'discount_satang' => 0,
                'tax_satang' => 0,
                'line_total_satang' => Satang::of($lineTotal),
                'notes' => $intent['notes'] ?? null,
            ],
            'modifiers' => $options->values()->map(fn (ModifierOption $o) => [
                'modifier_option_id' => $o->id,
                'group_name_snapshot' => $o->group?->name,
                'name_snapshot' => $o->name,
                'price_delta_satang' => $o->price_delta_satang,
                'quantity' => 1,
            ])->all(),
        ];
    }
}
