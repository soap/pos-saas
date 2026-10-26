<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { ApiError, api, newIdempotencyKey } from '@pos/api/client';
import type { Order, Payment } from '@pos/api/types';
import BaseModal from '@pos/components/BaseModal.vue';
import { formatBaht, parseBahtToSatang } from '@pos/lib/money';
import { toast, toastError } from '@pos/lib/toast';

type PaymentWithRefunds = Payment & { refunds?: { amount_satang: number; status: string }[] };

const props = defineProps<{ order: Order }>();
const emit = defineEmits<{ close: []; refunded: [] }>();

/** ยอดที่ยังคืนได้ = ชำระสำเร็จ − คืนแล้ว − คืนที่กำลังดำเนินการ (ตรงกับการตรวจฝั่ง server) */
function refundable(payment: PaymentWithRefunds): number {
    const pending = (payment.refunds ?? []).filter((r) => r.status === 'pending').reduce((sum, r) => sum + r.amount_satang, 0);

    return Math.max(0, payment.amount_satang - payment.refunded_satang - pending);
}

const payments = computed(() =>
    ((props.order.payments ?? []) as PaymentWithRefunds[]).filter((p) => ['succeeded', 'partially_refunded'].includes(p.status) && refundable(p) > 0),
);
const refundDue = computed(() => props.order.refund_due_satang ?? 0);

const paymentId = ref(payments.value[0]?.id ?? '');
const amountInput = ref('');
const reason = ref(refundDue.value > 0 ? 'คืนเงินส่วนที่ชำระเกิน/รายการถูกยกเลิก' : '');
/** QR ที่ provider คืนเงินอัตโนมัติไม่ได้ → ร้านโอนคืนเองแล้วบันทึกเลขอ้างอิง */
const manualTransfer = ref(false);
const transferReference = ref('');
const isSubmitting = ref(false);
let idempotencyKey: string | null = null;

const selected = computed(() => payments.value.find((p) => p.id === paymentId.value) ?? null);
const amountSatang = computed(() => parseBahtToSatang(amountInput.value));
const maxSatang = computed(() => (selected.value ? refundable(selected.value) : 0));
const isValid = computed(
    () => selected.value !== null && amountSatang.value !== null && amountSatang.value > 0 && amountSatang.value <= maxSatang.value && reason.value.trim().length >= 3,
);

// เปลี่ยนรายการชำระ → ตั้งยอดเริ่มต้นเป็นยอดที่ต้องคืน (ถ้ามี) ไม่เกินที่คืนได้
watch(
    selected,
    (payment) => {
        manualTransfer.value = false;
        const suggested = payment ? (refundDue.value > 0 ? Math.min(refundDue.value, refundable(payment)) : refundable(payment)) : 0;
        amountInput.value = suggested > 0 ? (suggested / 100).toFixed(2) : '';
    },
    { immediate: true },
);
watch([paymentId, amountInput, reason, manualTransfer, transferReference], () => (idempotencyKey = null));

async function submit(): Promise<void> {
    if (!isValid.value || !selected.value) {
        return;
    }
    isSubmitting.value = true;
    idempotencyKey ??= newIdempotencyKey();
    try {
        await api.postIdempotent(
            `/payments/${selected.value.id}/refunds`,
            {
                amount_satang: amountSatang.value,
                reason: reason.value.trim(),
                ...(manualTransfer.value ? { refund_method: 'manual_transfer', transfer_reference: transferReference.value.trim() || null } : {}),
            },
            idempotencyKey,
        );
        toast(`คืนเงิน ${formatBaht(amountSatang.value!)} แล้ว`, 'success');
        emit('refunded');
    } catch (e) {
        if (e instanceof ApiError && e.code === 'manual_refund_required') {
            manualTransfer.value = true;
            toast('ช่องทางนี้คืนเงินอัตโนมัติไม่ได้ — โอนคืนลูกค้าเองแล้วบันทึกเลขอ้างอิง', 'info', 7000);
        } else {
            toastError(e);
        }
    } finally {
        isSubmitting.value = false;
    }
}
</script>

<template>
    <BaseModal :title="`คืนเงิน — ออเดอร์ #${order.order_number}`" @close="emit('close')">
        <p v-if="payments.length === 0" class="py-6 text-center text-slate-500">ไม่มีรายการชำระที่คืนเงินได้</p>

        <form v-else id="refund-form" class="space-y-4" @submit.prevent="submit">
            <p v-if="refundDue > 0" class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800">
                ต้องคืนลูกค้า <strong class="tabular-nums">{{ formatBaht(refundDue) }}</strong> ก่อนปิดออเดอร์
            </p>

            <fieldset>
                <legend class="field-label">คืนจากการชำระ</legend>
                <div class="space-y-2">
                    <label
                        v-for="payment in payments"
                        :key="payment.id"
                        class="flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 ring-1"
                        :class="paymentId === payment.id ? 'bg-teal-50 ring-2 ring-teal-600' : 'ring-slate-300'"
                    >
                        <input v-model="paymentId" type="radio" name="refund-payment" :value="payment.id" class="size-5 accent-teal-700" />
                        <span class="flex-1">
                            <span class="block font-medium">{{ payment.method === 'cash' ? 'เงินสด' : 'QR พร้อมเพย์' }} · {{ formatBaht(payment.amount_satang) }}</span>
                            <span class="block text-xs text-slate-500">คืนได้อีก {{ formatBaht(refundable(payment)) }}</span>
                        </span>
                    </label>
                </div>
            </fieldset>

            <div>
                <label for="refund-amount" class="field-label">จำนวนเงินที่คืน (บาท)</label>
                <input id="refund-amount" v-model="amountInput" type="text" inputmode="decimal" autocomplete="off" required class="input text-right text-xl tabular-nums" />
                <p v-if="amountSatang !== null && amountSatang > maxSatang" class="mt-1 text-sm text-rose-600">เกินยอดที่คืนได้ ({{ formatBaht(maxSatang) }})</p>
            </div>

            <div>
                <label for="refund-reason" class="field-label">เหตุผล (อย่างน้อย 3 ตัวอักษร)</label>
                <input id="refund-reason" v-model="reason" type="text" minlength="3" maxlength="500" required class="input" />
            </div>

            <p v-if="selected?.method === 'cash'" class="text-sm text-slate-600">จ่ายเงินสดคืนจากลิ้นชัก — ต้องเปิดกะแคชเชียร์อยู่</p>

            <div v-if="manualTransfer" class="space-y-2 rounded-xl bg-amber-50 p-3">
                <p class="text-sm text-amber-900">โอนเงินคืนเข้าบัญชีลูกค้าเองก่อน แล้วบันทึกเลขอ้างอิงการโอน</p>
                <label for="refund-reference" class="field-label">เลขอ้างอิงการโอน (ถ้ามี)</label>
                <input id="refund-reference" v-model="transferReference" type="text" maxlength="128" class="input" />
            </div>
        </form>

        <template v-if="payments.length > 0" #footer>
            <button type="button" class="btn btn-secondary flex-1" @click="emit('close')">ยกเลิก</button>
            <button type="submit" form="refund-form" class="btn btn-danger flex-2" :disabled="isSubmitting || !isValid">
                {{ isSubmitting ? 'กำลังบันทึก…' : manualTransfer ? 'บันทึกว่าโอนคืนแล้ว' : 'คืนเงิน' }}
            </button>
        </template>
    </BaseModal>
</template>
