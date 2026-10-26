<script setup lang="ts">
import { computed, ref } from 'vue';
import { api } from '@pos/api/client';
import type { CashierShift } from '@pos/api/types';
import BaseModal from '@pos/components/BaseModal.vue';
import { formatBaht, parseBahtToSatang } from '@pos/lib/money';
import { toast, toastError } from '@pos/lib/toast';
import { useSessionStore } from '@pos/stores/session';

const emit = defineEmits<{ close: [] }>();

const session = useSessionStore();
const amountInput = ref('');
const note = ref('');
const isSubmitting = ref(false);
const closedShift = ref<CashierShift | null>(null);

const shift = computed(() => session.shift);
const amountSatang = computed(() => parseBahtToSatang(amountInput.value));

async function submit(): Promise<void> {
    if (amountSatang.value === null) {
        return;
    }
    isSubmitting.value = true;
    try {
        if (shift.value) {
            closedShift.value = (
                await api.post<{ data: CashierShift }>(`/cashier-shifts/${shift.value.id}/close`, {
                    counted_cash_satang: amountSatang.value,
                    note: note.value.trim() || null,
                })
            ).data;
            await session.refreshShift();
        } else {
            await api.post('/cashier-shifts/open', { opening_float_satang: amountSatang.value });
            await session.refreshShift();
            toast('เปิดกะแล้ว', 'success');
            emit('close');
        }
    } catch (e) {
        toastError(e);
    } finally {
        isSubmitting.value = false;
    }
}
</script>

<template>
    <BaseModal :title="closedShift ? 'ปิดกะแล้ว' : shift ? 'ปิดกะแคชเชียร์' : 'เปิดกะแคชเชียร์'" @close="emit('close')">
        <dl v-if="closedShift" class="space-y-2">
            <div class="flex justify-between"><dt class="text-slate-500">เงินสดที่ควรมี</dt><dd class="tabular-nums">{{ formatBaht(closedShift.expected_cash_satang ?? 0) }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">นับได้</dt><dd class="tabular-nums">{{ formatBaht(closedShift.counted_cash_satang ?? 0) }}</dd></div>
            <div class="flex justify-between text-lg font-semibold">
                <dt>ส่วนต่าง</dt>
                <dd class="tabular-nums" :class="(closedShift.variance_satang ?? 0) === 0 ? 'text-emerald-700' : 'text-rose-600'">
                    {{ formatBaht(closedShift.variance_satang ?? 0) }}
                </dd>
            </div>
        </dl>

        <form v-else id="shift-form" class="space-y-4" @submit.prevent="submit">
            <dl v-if="shift" class="space-y-1 rounded-xl bg-slate-50 px-4 py-3 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500">เงินทอนตั้งต้น</dt><dd class="tabular-nums">{{ formatBaht(shift.opening_float_satang) }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">เงินสดที่ควรมีตอนนี้</dt><dd class="tabular-nums">{{ formatBaht(shift.expected_cash_satang ?? 0) }}</dd></div>
            </dl>
            <div>
                <label for="shift-amount" class="field-label">{{ shift ? 'เงินสดที่นับได้ (บาท)' : 'เงินทอนตั้งต้นในลิ้นชัก (บาท)' }}</label>
                <input id="shift-amount" v-model="amountInput" type="text" inputmode="decimal" autocomplete="off" required class="input text-right text-xl tabular-nums" placeholder="0.00" />
            </div>
            <div v-if="shift">
                <label for="shift-note" class="field-label">หมายเหตุ</label>
                <input id="shift-note" v-model="note" type="text" maxlength="500" class="input" />
            </div>
        </form>

        <template #footer>
            <button v-if="closedShift" type="button" class="btn btn-primary w-full" @click="emit('close')">ตกลง</button>
            <template v-else>
                <button type="button" class="btn btn-secondary flex-1" @click="emit('close')">ยกเลิก</button>
                <button
                    type="submit"
                    form="shift-form"
                    class="btn flex-2"
                    :class="shift ? 'btn-danger' : 'btn-primary'"
                    :disabled="isSubmitting || amountSatang === null || (shift !== null && !session.can('cashier.close'))"
                >
                    {{ shift ? 'ปิดกะ' : 'เปิดกะ' }}
                </button>
            </template>
        </template>
    </BaseModal>
</template>
