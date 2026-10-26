<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Http\Resources\ProductResource;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\ModifierGroup;
use App\Modules\Catalog\Models\ModifierOption;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\MenuService;
use App\Support\Errors\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    public function __construct(private TenantContext $ctx, private AuditLogger $audit, private MenuService $menu) {}

    // ---------------- Categories ----------------

    public function categories(): JsonResponse
    {
        return response()->json(['data' => Category::query()->orderBy('sort_order')->orderBy('name')->get()]);
    }

    public function createCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $category = Category::create($data);
        $this->audit->log('catalog.category_created', $category, $data);

        return response()->json(['data' => $category], 201);
    }

    public function updateCategory(Request $request, string $id): JsonResponse
    {
        $category = Category::query()->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $category->update($data);
        $this->audit->log('catalog.category_updated', $category, $data);

        return response()->json(['data' => $category]);
    }

    // ---------------- Products ----------------

    public function products(Request $request): JsonResponse
    {
        $q = Product::query()->with('modifierGroups:id')->orderBy('sort_order')->orderBy('name');
        if ($request->filled('category_id')) {
            $q->where('category_id', $request->query('category_id'));
        }

        return response()->json(['data' => ProductResource::collection($q->get())]);
    }

    public function createProduct(Request $request): JsonResponse
    {
        $data = $this->validateProduct($request, null);
        $product = DB::transaction(function () use ($data) {
            $product = Product::create(collect($data)->except('modifier_group_ids')->all());
            $this->syncGroups($product, $data['modifier_group_ids'] ?? null);

            return $product;
        });
        $this->audit->log('catalog.product_created', $product, $data);

        return response()->json(['data' => new ProductResource($product->load('modifierGroups:id'))], 201);
    }

    public function updateProduct(Request $request, string $id): JsonResponse
    {
        $product = Product::query()->findOrFail($id);
        $data = $this->validateProduct($request, $product);
        $before = $product->only(array_keys($data));
        DB::transaction(function () use ($product, $data) {
            $product->update(collect($data)->except('modifier_group_ids')->all());
            $this->syncGroups($product, $data['modifier_group_ids'] ?? null);
        });
        // ราคาใหม่ไม่กระทบ order item เดิม เพราะ order เก็บ snapshot ไว้แล้ว
        $this->audit->log('catalog.product_updated', $product, ['before' => $before, 'after' => $data]);

        return response()->json(['data' => new ProductResource($product->fresh()->load('modifierGroups:id'))]);
    }

    /** PUT /products/{id}/store-price — ราคา/การขายเฉพาะสาขา (ต้องมี X-Store-Id) */
    public function setStorePrice(Request $request, string $id): JsonResponse
    {
        $product = Product::query()->findOrFail($id);
        $store = $this->ctx->store();
        $data = $request->validate([
            'price_satang' => ['required', 'integer', 'min:0'],
            'is_available' => ['required', 'boolean'],
        ]);
        DB::table('store_products')->upsert([[
            'tenant_id' => $store->tenant_id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'price_satang' => $data['price_satang'],
            'is_available' => $data['is_available'],
            'tax_profile' => 'none',
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['tenant_id', 'store_id', 'product_id'], ['price_satang', 'is_available', 'updated_at']);
        $this->audit->log('catalog.store_price_set', $product, $data);

        return response()->json(['data' => ['product_id' => $product->id, 'store_id' => $store->id] + $data]);
    }

    /** GET /store-prices — ราคา/การขายเฉพาะสาขาที่ตั้งไว้ (สินค้าที่ไม่อยู่ในรายการใช้ราคาตั้งต้น) */
    public function storePrices(): JsonResponse
    {
        $store = $this->ctx->store();
        $rows = DB::table('store_products')
            ->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)
            ->get(['product_id', 'price_satang', 'is_available'])
            ->map(fn ($r) => [
                'product_id' => $r->product_id,
                'price_satang' => (int) $r->price_satang,
                'is_available' => (bool) $r->is_available,
            ]);

        return response()->json(['data' => $rows]);
    }

    /** DELETE /products/{id}/store-price — กลับไปใช้ราคาตั้งต้นและขายตามสถานะสินค้า */
    public function clearStorePrice(string $id): JsonResponse
    {
        $product = Product::query()->findOrFail($id);
        $store = $this->ctx->store();
        $deleted = DB::table('store_products')
            ->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)->where('product_id', $product->id)
            ->delete();
        if ($deleted > 0) {
            $this->audit->log('catalog.store_price_cleared', $product, ['store_id' => $store->id]);
        }

        return response()->json(['removed' => $deleted]);
    }

    /** GET /menu — เมนูของสาขาสำหรับหน้าจอ POS (ราคาสาขา + modifiers) */
    public function menu(): JsonResponse
    {
        return response()->json(['data' => $this->menu->menuFor($this->ctx->store())]);
    }

    // ---------------- Modifiers ----------------

    public function modifierGroups(): JsonResponse
    {
        return response()->json(['data' => ModifierGroup::query()->with('options')->orderBy('sort_order')->get()]);
    }

    public function createModifierGroup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'min_select' => ['required', 'integer', 'min:0'],
            'max_select' => ['required', 'integer', 'min:1', 'gte:min_select'],
            'is_required' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
            'options' => ['required', 'array', 'min:1'],
            'options.*.name' => ['required', 'string', 'max:255'],
            'options.*.price_delta_satang' => ['required', 'integer'],
        ]);
        $group = DB::transaction(function () use ($data) {
            $group = ModifierGroup::create(collect($data)->except('options')->all());
            foreach ($data['options'] as $i => $o) {
                ModifierOption::create($o + ['modifier_group_id' => $group->id, 'sort_order' => $i, 'tenant_id' => $group->tenant_id]);
            }

            return $group;
        });
        $this->audit->log('catalog.modifier_group_created', $group, $data);

        return response()->json(['data' => $group->load('options')], 201);
    }

    /** PATCH /modifier-groups/{id} — ตรวจ min/max กับค่าหลังแก้ (ส่งมาบางช่องได้); ออเดอร์เดิมเก็บ snapshot ไว้แล้ว */
    public function updateModifierGroup(Request $request, string $id): JsonResponse
    {
        $group = ModifierGroup::query()->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'min_select' => ['sometimes', 'integer', 'min:0'],
            'max_select' => ['sometimes', 'integer', 'min:1'],
            'is_required' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
        ]);
        if (($data['max_select'] ?? $group->max_select) < ($data['min_select'] ?? $group->min_select)) {
            throw ValidationException::withMessages(['max_select' => 'เลือกได้สูงสุดต้องไม่น้อยกว่าเลือกอย่างน้อย']);
        }
        $group->update($data);
        $this->audit->log('catalog.modifier_group_updated', $group, $data);

        return response()->json(['data' => $group->load('options')]);
    }

    /** POST /modifier-groups/{id}/options — เพิ่มตัวเลือกต่อท้ายกลุ่ม */
    public function addModifierOption(Request $request, string $id): JsonResponse
    {
        $group = ModifierGroup::query()->findOrFail($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price_delta_satang' => ['required', 'integer'],
        ]);
        $option = ModifierOption::create($data + [
            'modifier_group_id' => $group->id,
            'tenant_id' => $group->tenant_id,
            'sort_order' => (int) $group->options()->max('sort_order') + 1,
        ]);
        $this->audit->log('catalog.modifier_option_created', $option, $data);

        return response()->json(['data' => $option], 201);
    }

    public function updateModifierOption(Request $request, string $id): JsonResponse
    {
        $option = ModifierOption::query()->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'price_delta_satang' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $option->update($data);
        $this->audit->log('catalog.modifier_option_updated', $option, $data);

        return response()->json(['data' => $option]);
    }

    // ---------------------------------------------

    private function validateProduct(Request $request, ?Product $product): array
    {
        $tenantId = $this->ctx->tenant()->id;
        $req = $product ? 'sometimes' : 'required';

        return $request->validate([
            'category_id' => ['nullable', 'uuid', Rule::exists('categories', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'sku' => ['nullable', 'string', 'max:64', Rule::unique('products', 'sku')->where('tenant_id', $tenantId)->ignore($product?->id)],
            'name' => [$req, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'base_price_satang' => [$req, 'integer', 'min:0'],
            'status' => ['sometimes', 'in:active,inactive'],
            'sort_order' => ['sometimes', 'integer'],
            // null = ครัวหลัก
            'kitchen_station_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('kitchen_stations', 'id')->where('tenant_id', $tenantId)],
            'modifier_group_ids' => ['sometimes', 'array'],
            'modifier_group_ids.*' => ['uuid'],
        ]);
    }

    private function syncGroups(Product $product, ?array $groupIds): void
    {
        if ($groupIds === null) {
            return;
        }
        $groupIds = array_values(array_unique($groupIds));
        // ป้องกันการผูก modifier group ข้าม tenant (global scope กรองแล้ว + FK composite ใน DB)
        if (ModifierGroup::query()->whereIn('id', $groupIds)->count() !== count($groupIds)) {
            throw ApiException::unprocessable('modifier_group_invalid', 'modifier group ไม่ถูกต้อง');
        }
        $sync = [];
        foreach ($groupIds as $i => $gid) {
            $sync[$gid] = ['tenant_id' => $product->tenant_id, 'sort_order' => $i];
        }
        $product->modifierGroups()->sync($sync);
    }
}
