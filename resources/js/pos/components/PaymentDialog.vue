<script setup lang="ts">
import QRCode from 'qrcode';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { api, newIdempotencyKey } from '@pos/api/client';
import type { Order, Payment, QrInstruction } from '@pos/api/types';
import BaseModal from '@pos/components/BaseModal.vue';
import { formatBaht, parseBahtToSatang } from '@pos/lib/money';
import { errorMessage, toastError } from '@pos/lib/toast';

const props = defineProps<{ order: Order }>();
const emit = defineEmits<{ close: []; paid: [payment: Payment] }>();

const POLL_MS = 3000;
/** ถาม provider ตรง ๆ (refresh=1) ทุก ๆ N รอบ — เผื่อ webhook มาช้า; route นี้จำกัด 60 ครั้ง/นาที */
const REFRESH_EVERY = 5;
const IN_FLIGHT = ['pending', 'processing'];

const method = ref<'cash' | 'qr'>('cash');
const balanceDue = computed(() => props.order.balance_due_satang ?? props.order.total_satang);

// ---------- เงินสด ----------
const tenderedInput = ref('');
const tenderedSatang = computed(() => parseBahtToSatang(tenderedInput.value));
const changeSatang = computed(() => (tenderedSatang.value === null ? null : tenderedSatang.value - balanceDue.value));
const quickTenders = computed(() => {
    const due = balanceDue.value;
    const candidates = [due, ...[2000, 5000, 10000, 50000, 100000].map((note) => Math.ceil(due / note) * note)];

    return [...new Set(candidates)].sort((a, b) => a - b).slice(0, 5);
});
const cashPayment = ref<Payment | null>(null);
const isPaying = ref(false);
let cashKey: string | null = null;
watch(tenderedInput, () => (cashKey = null));

async function payCash(): Promise<void> {
    if (tenderedSatang.value === null || tenderedSatang.value < balanceDue.value) {
        return;
    }
    isPaying.value = true;
    cashKey ??= newIdempotencyKey();
    try {
        const response = await api.postIdempotent<{ data: Payment }>(
            `/orders/${props.order.id}/payments`,
            { method: 'cash', tendered_satang: tenderedSatang.value },
            cashKey,
        );
        cashPayment.value = response.data;
    } catch (e) {
        toastError(e);
    } finally {
        isPaying.value = false;
    }
}

// ---------- QR ----------
const qrPayment = ref<Payment | null>(null);
const qr = ref<QrInstruction | null>(null);
const qrImage = ref<string | null>(null);
const qrError = ref<string | null>(null);
const slipInput = ref('');
const isSubmittingSlip = ref(false);
let pollTimer: ReturnType<typeof setTimeout> | null = null;
let pollCount = 0;

const qrInFlight = computed(() => qrPayment.value !== null && IN_FLIGHT.includes(qrPayment.value.status));
const slipRejection = computed(() => qrPayment.value?.slip?.rejection?.reason ?? null);
const slipPending = computed(() => qrPayment.value?.slip?.submitted_at != null && !slipRejection.value);

async function startQr(): Promise<void> {
    isPaying.value = true;
    qrError.value = null;
    try {
        const response = await api.postIdempotent<{ data: Payment; qr: QrInstruction }>(
            `/orders/${props.order.id}/payments`,
            { method: 'qr' },
            newIdempotencyKey(),
        );
        qrPayment.value = response.data;
        qr.value = response.qr;
        qrImage.value = response.qr.payload ? await QRCode.toDataURL(response.qr.payload, { width: 320, margin: 1 }) : response.qr.image_url;
        schedulePoll();
    } catch (e) {
        qrError.value = errorMessage(e);
    } finally {
        isPaying.value = false;
    }
}

function schedulePoll(): void {
    stopPolling();
    pollTimer = setTimeout(pollPayment, POLL_MS);
}

function stopPolling(): void {
    if (pollTimer !== null) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }
}

async function pollPayment(): Promise<void> {
    if (!qrPayment.value) {
        return;
    }
    pollCount++;
    try {
        const refresh = pollCount % REFRESH_EVERY === 0 ? 1 : undefined;
        qrPayment.value = (await api.get<{ data: Payment }>(`/payments/${qrPayment.value.id}`, { refresh })).data;
    } catch {
        // เน็ตสะดุดชั่วคราว — poll รอบถัดไปต่อ
    }
    if (qrPayment.value.status === 'succeeded') {
        emit('paid', qrPayment.value);
    } else if (qrInFlight.value) {
        schedulePoll();
    }
}

async function submitSlip(): Promise<void> {
    const payload = slipInput.value.trim();
    if (!qrPayment.value || payload === '') {
        return;
    }
    isSubmittingSlip.value = true;
    try {
        qrPayment.value = (await api.postIdempotent<{ data: Payment }>(`/payments/${qrPayment.value.id}/slip`, { slip_payload: payload }, newIdempotencyKey())).data;
        slipInput.value = '';
        schedulePoll();
    } catch (e) {
        toastError(e);
    } finally {
        isSubmittingSlip.value = false;
    }
}

async function cancelQr(): Promise<void> {
    if (!qrPayment.value) {
        return;
    }
    stopPolling();
    try {
        qrPayment.value = (await api.post<{ data: Payment }>(`/payments/${qrPayment.value.id}/cancel`)).data;
    } catch (e) {
        toastError(e);
        await pollPayment();

        return;
    }
    resetQr();
}

function resetQr(): void {
    stopPolling();
    qrPayment.value = null;
    qr.value = null;
    qrImage.value = null;
    pollCount = 0;
}

function close(): void {
    if (cashPayment.value) {
        emit('paid', cashPayment.value);

        return;
    }
    emit('close');
}

onBeforeUnmount(stopPolling);
</script>

<template>
    <BaseModal title="ชำระเงิน" :dismissible="!qrInFlight && !isPaying" @close="close">
        <div class="mb-5 rounded-2xl bg-slate-50 px-4 py-3 text-center">
            <p class="text-sm text-slate-500">ออเดอร์ #{{ order.order_number }} · ยอดที่ต้องชำระ</p>
            <p class="text-3xl font-bold text-teal-800 tabular-nums">{{ formatBaht(balanceDue) }}</p>
        </div>

        <!-- ชำระเงินสดสำเร็จ -->
        <div v-if="cashPayment" class="space-y-2 py-4 text-center">
            <p class="text-lg font-semibold text-emerald-700">รับเงินสดเรียบร้อย</p>
            <p class="text-sm text-slate-500">เงินทอน</p>
            <p class="text-4xl font-bold tabular-nums">{{ formatBaht(cashPayment.change_satang ?? 0) }}</p>
        </div>

        <template v-else>
            <div v-if="!qrPayment" class="mb-5 grid grid-cols-2 gap-2 rounded-xl bg-slate-100 p-1" role="tablist">
                <button
                    v-for="tab in [{ value: 'cash', label: 'เงินสด' }, { value: 'qr', label: 'QR พร้อมเพย์' }] as const"
                    :key="tab.value"
                    type="button"
                    role="tab"
                    class="rounded-lg py-2.5 font-medium"
                    :class="method === tab.value ? 'bg-white shadow-sm' : 'text-slate-600'"
                    :aria-selected="method === tab.value"
                    @click="method = tab.value"
                >
                    {{ tab.label }}
                </button>
            </div>

            <!-- เงินสด -->
            <form v-if="method === 'cash'" class="space-y-4" @submit.prevent="payCash">
                <div>
                    <label for="tendered" class="field-label">รับเงินมา (บาท)</label>
                    <input id="tendered" v-model="tenderedInput" type="text" inputmode="decimal" autocomplete="off" class="input text-right text-2xl tabular-nums" placeholder="0.00" />
                </div>
                <div class="grid grid-cols-3 gap-2 sm:grid-cols-5">
                    <button
                        v-for="amount in quickTenders"
                        :key="amount"
                        type="button"
                        class="btn btn-secondary px-2 text-sm tabular-nums"
                        @click="tenderedInput = (amount / 100).toFixed(2)"
                    >
                        {{ amount === balanceDue ? 'พอดี' : formatBaht(amount) }}
                    </button>
                </div>
                <p v-if="changeSatang !== null" class="flex items-baseline justify-between text-lg">
                    <span>{{ changeSatang >= 0 ? 'เงินทอน' : 'ยังขาด' }}</span>
                    <span class="text-2xl font-bold tabular-nums" :class="changeSatang >= 0 ? 'text-slate-900' : 'text-rose-600'">{{ formatBaht(Math.abs(changeSatang)) }}</span>
                </p>
                <button type="submit" class="btn btn-primary w-full text-lg" :disabled="isPaying || changeSatang === null || changeSatang < 0">
                    {{ isPaying ? 'กำลังบันทึก…' : 'รับเงินสด' }}
                </button>
            </form>

            <!-- QR -->
            <div v-else class="space-y-4">
                <template v-if="!qrPayment">
                    <p v-if="qrError" class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700" role="alert">{{ qrError }}</p>
                    <button type="button" class="btn btn-primary w-full text-lg" :disabled="isPaying" @click="startQr">
                        {{ isPaying ? 'กำลังสร้าง QR…' : 'สร้าง QR สำหรับยอดนี้' }}
                    </button>
                </template>

                <template v-else-if="qrInFlight">
                    <img v-if="qrImage" :src="qrImage" alt="QR สำหรับชำระเงิน" class="mx-auto size-64 rounded-xl ring-1 ring-slate-200" />
                    <p v-else class="text-center text-sm text-rose-600">ไม่มีรูป QR จากผู้ให้บริการ</p>

                    <template v-if="qr?.requires_slip">
                        <p v-if="slipRejection" class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700" role="alert">
                            สลิปไม่ผ่าน: {{ slipRejection }} — กรุณาสแกนสลิปใหม่
                        </p>
                        <p v-else-if="slipPending" class="rounded-xl bg-amber-50 px-4 py-3 text-center text-sm text-amber-800">กำลังตรวจสอบสลิป…</p>
                        <form class="space-y-2" @submit.prevent="submitSlip">
                            <label for="slip" class="field-label">สแกน QR บนสลิปของลูกค้า</label>
                            <div class="flex gap-2">
                                <input id="slip" v-model="slipInput" type="text" autocomplete="off" class="input" placeholder="ยิงเครื่องสแกนที่ช่องนี้" />
                                <button type="submit" class="btn btn-primary shrink-0" :disabled="isSubmittingSlip || slipInput.trim() === ''">ตรวจสลิป</button>
                            </div>
                        </form>
                    </template>
                    <p v-else class="text-center text-sm text-slate-500">รอลูกค้าสแกนจ่าย ระบบจะยืนยันอัตโนมัติ…</p>

                    <button type="button" class="btn btn-secondary w-full text-rose-700" @click="cancelQr">ยกเลิก QR</button>
                </template>

                <template v-else>
                    <p class="rounded-xl bg-rose-50 px-4 py-3 text-center text-rose-700" role="alert">
                        การชำระไม่สำเร็จ ({{ qrPayment.status }})
                    </p>
                    <button type="button" class="btn btn-primary w-full" @click="resetQr">ลองใหม่</button>
                </template>
            </div>
        </template>

        <template v-if="cashPayment" #footer>
            <button type="button" class="btn btn-primary w-full" @click="close">เสร็จสิ้น</button>
        </template>
    </BaseModal>
</template>
