<script setup lang="ts">
import { computed, ref } from 'vue';
import BaseModal from '@pos/components/BaseModal.vue';
import { formatBaht, parseBahtToSatang } from '@pos/lib/money';
import { toast, toastError } from '@pos/lib/toast';
import { usePosStore } from '@pos/stores/pos';

const emit = defineEmits<{ close: [] }>();

const pos = usePosStore();
const amountInput = ref(pos.order && pos.order.discount_satang > 0 ? (pos.order.discount_satang / 100).toFixed(2) : '');
const reason = ref('');
const isSubmitting = ref(false);

const amountSatang = computed(() => parseBahtToSatang(amountInput.value));

async function submit(): Promise<void> {
    if (amountSatang.value === null) {
        return;
    }
    isSubmitting.value = true;
    try {
        await pos.applyDiscount(amountSatang.value, reason.value.trim() || null);
        toast('บันทึกส่วนลดแล้ว', 'success');
        emit('close');
    } catch (e) {
        toastError(e);
    } finally {
        isSubmitting.value = false;
    }
}
</script>

<template>
    <BaseModal title="ส่วนลดท้ายบิล" @close="emit('close')">
        <form id="discount-form" class="space-y-4" @submit.prevent="submit">
            <p class="text-sm text-slate-500">ยอดรวมก่อนส่วนลด {{ formatBaht(pos.order?.subtotal_satang ?? 0) }} — ใส่ 0 เพื่อยกเลิกส่วนลด</p>
            <div>
                <label for="discount-amount" class="field-label">ส่วนลด (บาท)</label>
                <input id="discount-amount" v-model="amountInput" type="text" inputmode="decimal" autocomplete="off" required class="input text-right text-xl tabular-nums" />
            </div>
            <div>
                <label for="discount-reason" class="field-label">เหตุผล</label>
                <input id="discount-reason" v-model="reason" type="text" maxlength="255" class="input" placeholder="เช่น ลูกค้าประจำ" />
            </div>
        </form>
        <template #footer>
            <button type="button" class="btn btn-secondary flex-1" @click="emit('close')">ยกเลิก</button>
            <button type="submit" form="discount-form" class="btn btn-primary flex-2" :disabled="isSubmitting || amountSatang === null">บันทึกส่วนลด</button>
        </template>
    </BaseModal>
</template>
