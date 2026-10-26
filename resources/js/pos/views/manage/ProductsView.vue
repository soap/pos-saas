<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ApiError, api } from '@pos/api/client';
import type { Category, ManagedModifierGroup, Product } from '@pos/api/types';
import BaseModal from '@pos/components/BaseModal.vue';
import { formatBaht, parseBahtToSatang } from '@pos/lib/money';
import { toast, toastError } from '@pos/lib/toast';

interface ProductForm {
    name: string;
    sku: string;
    category_id: string;
    description: string;
    price: string;
    status: 'active' | 'inactive';
    sort_order: number;
    modifier_group_ids: string[];
}

const products = ref<Product[]>([]);
const categories = ref<Category[]>([]);
const groups = ref<ManagedModifierGroup[]>([]);
const isLoading = ref(true);
const search = ref('');
const categoryFilter = ref('');
const editing = ref<Product | 'new' | null>(null);
const form = ref<ProductForm>(emptyForm());
const formError = ref<ApiError | null>(null);
const isSaving = ref(false);

const categoryName = computed(() => new Map(categories.value.map((c) => [c.id, c.name])));
const filtered = computed(() => {
    const term = search.value.trim().toLowerCase();

    return products.value.filter(
        (p) =>
            (categoryFilter.value === '' || (categoryFilter.value === 'none' ? p.category_id === null : p.category_id === categoryFilter.value)) &&
            (term === '' || p.name.toLowerCase().includes(term) || p.sku?.toLowerCase().includes(term)),
    );
});
const priceSatang = computed(() => parseBahtToSatang(form.value.price));

function emptyForm(): ProductForm {
    return { name: '', sku: '', category_id: '', description: '', price: '', status: 'active', sort_order: 0, modifier_group_ids: [] };
}

async function load(): Promise<void> {
    try {
        const [productResponse, categoryResponse, groupResponse] = await Promise.all([
            api.get<{ data: Product[] }>('/products'),
            api.get<{ data: Category[] }>('/categories'),
            api.get<{ data: ManagedModifierGroup[] }>('/modifier-groups'),
        ]);
        products.value = productResponse.data;
        categories.value = categoryResponse.data;
        groups.value = groupResponse.data;
    } catch (e) {
        toastError(e);
    } finally {
        isLoading.value = false;
    }
}

function open(product: Product | 'new'): void {
    editing.value = product;
    formError.value = null;
    form.value =
        product === 'new'
            ? { ...emptyForm(), category_id: categoryFilter.value !== 'none' ? categoryFilter.value : '' }
            : {
                  name: product.name,
                  sku: product.sku ?? '',
                  category_id: product.category_id ?? '',
                  description: product.description ?? '',
                  price: (product.base_price_satang / 100).toFixed(2),
                  status: product.status,
                  sort_order: product.sort_order,
                  modifier_group_ids: [...(product.modifier_group_ids ?? [])],
              };
}

async function save(): Promise<void> {
    if (priceSatang.value === null) {
        return;
    }
    isSaving.value = true;
    formError.value = null;
    const payload = {
        name: form.value.name.trim(),
        sku: form.value.sku.trim() || null,
        category_id: form.value.category_id || null,
        description: form.value.description.trim() || null,
        base_price_satang: priceSatang.value,
        status: form.value.status,
        sort_order: form.value.sort_order,
        modifier_group_ids: form.value.modifier_group_ids,
    };
    try {
        if (editing.value === 'new') {
            await api.post('/products', payload);
        } else if (editing.value) {
            await api.patch(`/products/${editing.value.id}`, payload);
        }
        toast('บันทึกสินค้าแล้ว', 'success');
        editing.value = null;
        await load();
    } catch (e) {
        if (e instanceof ApiError && e.code === 'validation_failed') {
            formError.value = e;
        } else {
            toastError(e);
        }
    } finally {
        isSaving.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="mx-auto max-w-5xl space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">สินค้า</h1>
                <p class="text-sm text-slate-500">ราคาตั้งต้นใช้ทุกสาขา · เปลี่ยนราคาไม่กระทบออเดอร์เดิม</p>
            </div>
            <button type="button" class="btn btn-primary" @click="open('new')">+ เพิ่มสินค้า</button>
        </header>

        <div class="flex flex-col gap-2 sm:flex-row">
            <input v-model="search" type="search" class="input" placeholder="ค้นหาชื่อหรือ SKU" aria-label="ค้นหาสินค้า" />
            <select v-model="categoryFilter" class="input sm:w-56" aria-label="กรองตามหมวดหมู่">
                <option value="">ทุกหมวดหมู่</option>
                <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                <option value="none">ไม่มีหมวดหมู่</option>
            </select>
        </div>

        <p v-if="isLoading" class="text-slate-500">กำลังโหลด…</p>
        <p v-else-if="filtered.length === 0" class="rounded-2xl bg-white p-8 text-center text-slate-500 ring-1 ring-slate-200">ไม่พบสินค้า</p>
        <ul v-else class="divide-y divide-slate-100 overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200">
            <li v-for="product in filtered" :key="product.id">
                <button type="button" class="flex w-full items-center gap-4 px-4 py-3 text-left hover:bg-slate-50" @click="open(product)">
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium" :class="{ 'text-slate-400': product.status === 'inactive' }">{{ product.name }}</span>
                        <span class="block text-sm text-slate-500">
                            {{ product.category_id ? categoryName.get(product.category_id) : 'ไม่มีหมวดหมู่' }}<template v-if="product.sku"> · {{ product.sku }}</template>
                            <template v-if="product.modifier_group_ids?.length"> · ตัวเลือก {{ product.modifier_group_ids.length }} กลุ่ม</template>
                        </span>
                    </span>
                    <span v-if="product.status === 'inactive'" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600">ปิดขาย</span>
                    <span class="font-semibold tabular-nums">{{ formatBaht(product.base_price_satang) }}</span>
                </button>
            </li>
        </ul>

        <BaseModal v-if="editing" :title="editing === 'new' ? 'เพิ่มสินค้า' : 'แก้ไขสินค้า'" size="lg" @close="editing = null">
            <form id="product-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="save">
                <div class="sm:col-span-2">
                    <label for="product-name" class="field-label">ชื่อสินค้า</label>
                    <input id="product-name" v-model="form.name" type="text" required maxlength="255" class="input" />
                    <p v-if="formError?.fieldError('name')" class="mt-1 text-sm text-rose-600">{{ formError.fieldError('name') }}</p>
                </div>
                <div>
                    <label for="product-price" class="field-label">ราคา (บาท)</label>
                    <input id="product-price" v-model="form.price" type="text" inputmode="decimal" required class="input text-right tabular-nums" placeholder="0.00" />
                    <p v-if="form.price !== '' && priceSatang === null" class="mt-1 text-sm text-rose-600">รูปแบบราคาไม่ถูกต้อง</p>
                </div>
                <div>
                    <label for="product-sku" class="field-label">SKU (ไม่บังคับ)</label>
                    <input id="product-sku" v-model="form.sku" type="text" maxlength="64" class="input" />
                    <p v-if="formError?.fieldError('sku')" class="mt-1 text-sm text-rose-600">{{ formError.fieldError('sku') }}</p>
                </div>
                <div>
                    <label for="product-category" class="field-label">หมวดหมู่</label>
                    <select id="product-category" v-model="form.category_id" class="input">
                        <option value="">ไม่มีหมวดหมู่</option>
                        <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                    </select>
                </div>
                <div>
                    <label for="product-sort" class="field-label">ลำดับ</label>
                    <input id="product-sort" v-model.number="form.sort_order" type="number" step="1" class="input" />
                </div>
                <div class="sm:col-span-2">
                    <label for="product-description" class="field-label">รายละเอียด</label>
                    <textarea id="product-description" v-model="form.description" rows="2" maxlength="2000" class="input" />
                </div>
                <fieldset class="sm:col-span-2">
                    <legend class="field-label">ตัวเลือกเสริม</legend>
                    <p v-if="groups.length === 0" class="text-sm text-slate-500">ยังไม่มีกลุ่มตัวเลือก — สร้างได้ที่เมนู "ตัวเลือกเสริม"</p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label v-for="group in groups" :key="group.id" class="flex items-center gap-3 rounded-xl px-3 py-2 ring-1 ring-slate-200">
                            <input v-model="form.modifier_group_ids" type="checkbox" :value="group.id" class="size-5 accent-teal-700" />
                            <span>
                                <span class="block font-medium">{{ group.name }}</span>
                                <span class="block text-xs text-slate-500">{{ group.options.map((o) => o.name).join(', ') }}</span>
                            </span>
                        </label>
                    </div>
                </fieldset>
                <label class="flex items-center gap-3 sm:col-span-2">
                    <input v-model="form.status" type="checkbox" true-value="active" false-value="inactive" class="size-5 accent-teal-700" />
                    <span>เปิดขาย (แสดงในเมนู POS)</span>
                </label>
            </form>
            <template #footer>
                <button type="button" class="btn btn-secondary flex-1" @click="editing = null">ยกเลิก</button>
                <button type="submit" form="product-form" class="btn btn-primary flex-2" :disabled="isSaving || priceSatang === null">บันทึก</button>
            </template>
        </BaseModal>
    </div>
</template>
