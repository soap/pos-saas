<script setup lang="ts">
import { ref } from 'vue';
import BaseModal from '@pos/components/BaseModal.vue';
import { toast, toastError } from '@pos/lib/toast';
import { usePosStore } from '@pos/stores/pos';

const emit = defineEmits<{ close: [] }>();

const pos = usePosStore();
const reason = ref('');
const isSubmitting = ref(false);

async function submit(): Promise<void> {
    isSubmitting.value = true;
    try {
        await pos.cancelOrder(reason.value.trim());
        toast('ยกเลิกออเดอร์แล้ว', 'success');
        emit('close');
    } catch (e) {
        toastError(e);
    } finally {
        isSubmitting.value = false;
    }
}
</script>

<template>
    <BaseModal :title="`ยกเลิกออเดอร์ #${pos.order?.order_number ?? ''}`" @close="emit('close')">
        <form id="cancel-form" class="space-y-3" @submit.prevent="submit">
            <p class="text-sm text-slate-600">ออเดอร์ที่ชำระเงินแล้วต้องคืนเงินก่อน ระบบจะแจ้งหากยกเลิกไม่ได้</p>
            <div>
                <label for="cancel-reason" class="field-label">เหตุผล (อย่างน้อย 3 ตัวอักษร)</label>
                <input id="cancel-reason" v-model="reason" type="text" minlength="3" maxlength="500" required class="input" />
            </div>
        </form>
        <template #footer>
            <button type="button" class="btn btn-secondary flex-1" @click="emit('close')">ไม่ยกเลิก</button>
            <button type="submit" form="cancel-form" class="btn btn-danger flex-2" :disabled="isSubmitting || reason.trim().length < 3">ยืนยันยกเลิก</button>
        </template>
    </BaseModal>
</template>
