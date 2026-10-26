<script setup lang="ts">
import { computed, ref } from 'vue';
import { api } from '@pos/api/client';
import type { Product, StorePrice } from '@pos/api/types';
import BaseModal from '@pos/components/BaseModal.vue';
import { formatBaht, parseBahtToSatang } from '@pos/lib/money';
import { toast, toastError } from '@pos/lib/toast';
import { useSessionStore } from '@pos/stores/session';

const props = defineProps<{ product: Product; current: StorePrice | null }>();
const emit = defineEmits<{ close: []; saved: [] }>();

const session = useSessionStore();
const priceInput = ref(((props.current?.price_satang ?? props.product.base_price_satang) / 100).toFixed(2));
const isAvailable = ref(props.current?.is_available ?? true);
const isSaving = ref(false);

const priceSatang = computed(() => parseBahtToSatang(priceInput.value));

async function save(): Promise<void> {
    if (priceSatang.value === null) {
        return;
    }
    isSaving.value = true;
    try {
        await api.put(`/products/${props.product.id}/store-price`, { price_satang: priceSatang.value, is_available: isAvailable.value });
        toast('บันทึกราคาสาขาแล้ว', 'success');
        emit('saved');
    } catch (e) {
        toastError(e);
    } finally {
        isSaving.value = false;
    }
}

async function reset(): Promise<void> {
    isSaving.value = true;
    try {
        await api.delete(`/products/${props.product.id}/store-price`);
        toast('กลับไปใช้ราคาตั้งต้นแล้ว', 'success');
        emit('saved');
    } catch (e) {
        toastError(e);
    } finally {
        isSaving.value = false;
    }
}
</script>

<template>
    <BaseModal :title="`ราคาที่สาขา ${session.context?.store?.name ?? ''}`" @close="emit('close')">
        <form id="store-price-form" class="space-y-4" @submit.prevent="save">
            <p class="text-sm text-slate-600">
                <strong>{{ product.name }}</strong> · ราคาตั้งต้นทุกสาขา {{ formatBaht(product.base_price_satang) }}
                <template v-if="!current"> · สาขานี้ยังใช้ราคาตั้งต้น</template>
            </p>
            <div>
                <label for="store-price" class="field-label">ราคาที่สาขานี้ (บาท)</label>
                <input id="store-price" v-model="priceInput" type="text" inputmode="decimal" required class="input text-right text-xl tabular-nums" />
                <p v-if="priceSatang === null" class="mt-1 text-sm text-rose-600">รูปแบบราคาไม่ถูกต้อง</p>
            </div>
            <label class="flex items-center gap-3">
                <input v-model="isAvailable" type="checkbox" class="size-5 accent-teal-700" />
                <span>ขายที่สาขานี้ (ไม่ติ๊ก = ซ่อนจากเมนู POS ของสาขานี้ เช่น ของหมด)</span>
            </label>
        </form>
        <template #footer>
            <button v-if="current" type="button" class="btn btn-secondary flex-1 text-sm" :disabled="isSaving" @click="reset">ใช้ราคาตั้งต้น</button>
            <button type="submit" form="store-price-form" class="btn btn-primary flex-2" :disabled="isSaving || priceSatang === null">บันทึก</button>
        </template>
    </BaseModal>
</template>
