<script setup lang="ts">
import { computed, ref } from 'vue';
import type { MenuProduct } from '@pos/api/types';
import { formatBaht } from '@pos/lib/money';
import { usePosStore } from '@pos/stores/pos';

const emit = defineEmits<{ pick: [product: MenuProduct] }>();

const pos = usePosStore();
const activeCategory = ref<string>('all');
const search = ref('');

const categories = computed(() => {
    const menu = pos.menu;
    if (!menu) {
        return [];
    }
    const list = menu.categories.filter((c) => c.products.length > 0).map((c) => ({ id: c.id, name: c.name, products: c.products }));
    if (menu.uncategorized.length > 0) {
        list.push({ id: 'uncategorized', name: 'อื่น ๆ', products: menu.uncategorized });
    }

    return list;
});

const products = computed(() => {
    const term = search.value.trim().toLowerCase();
    const source =
        activeCategory.value === 'all' ? categories.value.flatMap((c) => c.products) : (categories.value.find((c) => c.id === activeCategory.value)?.products ?? []);

    return term === '' ? source : source.filter((p) => p.name.toLowerCase().includes(term) || p.sku?.toLowerCase().includes(term));
});
</script>

<template>
    <section class="flex min-h-0 flex-1 flex-col">
        <div class="space-y-3 border-b border-slate-200 bg-white px-4 py-3">
            <input v-model="search" type="search" class="input" placeholder="ค้นหาเมนูหรือ SKU" aria-label="ค้นหาเมนู" />
            <nav class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1" aria-label="หมวดหมู่">
                <button
                    v-for="category in [{ id: 'all', name: 'ทั้งหมด' }, ...categories]"
                    :key="category.id"
                    type="button"
                    class="shrink-0 rounded-full px-4 py-2 text-sm font-medium whitespace-nowrap"
                    :class="activeCategory === category.id ? 'bg-teal-700 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
                    :aria-pressed="activeCategory === category.id"
                    @click="activeCategory = category.id"
                >
                    {{ category.name }}
                </button>
            </nav>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto p-4">
            <p v-if="!pos.menu" class="text-slate-500">กำลังโหลดเมนู…</p>
            <p v-else-if="products.length === 0" class="py-12 text-center text-slate-500">ไม่พบเมนู</p>
            <ul v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                <li v-for="product in products" :key="product.id">
                    <button
                        type="button"
                        class="flex h-full min-h-28 w-full flex-col justify-between rounded-2xl bg-white p-4 text-left shadow-sm ring-1 ring-slate-200 transition hover:ring-teal-600 active:scale-[0.98]"
                        @click="emit('pick', product)"
                    >
                        <span class="line-clamp-2 font-medium">{{ product.name }}</span>
                        <span class="mt-2 flex items-end justify-between gap-2">
                            <span class="font-semibold text-teal-800 tabular-nums">{{ formatBaht(product.price_satang) }}</span>
                            <span v-if="product.modifier_groups.length > 0" class="text-xs text-slate-500">มีตัวเลือก</span>
                        </span>
                    </button>
                </li>
            </ul>
        </div>
    </section>
</template>
