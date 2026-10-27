<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { ApiError, api } from '@pos/api/client';
import type { BillingInvoice, BillingSummary } from '@pos/api/types';
import { formatBaht } from '@pos/lib/money';
import { toast, toastError } from '@pos/lib/toast';

/**
 * ค่าแพ็กเกจของร้าน — อยู่นอก ManageLayout เพราะร้านที่ถูกระงับ (402) โหลด /context ไม่ได้
 * แต่ route /billing ฝั่ง server ไม่ตรวจ subscription จึงเปิดหน้านี้และจ่ายได้เสมอ
 */

const POLL_MS = 5000;
/** ถาม Omise ตรง ๆ (refresh=1) ทุก ๆ N รอบ เผื่อ webhook มาช้า */
const REFRESH_EVERY = 3;

const STATUS_LABELS: Record<string, string> = {
    trialing: 'ทดลองใช้',
    active: 'ใช้งานอยู่',
    past_due: 'ค้างชำระ',
    cancelled: 'ยกเลิกแล้ว',
};

const summary = ref<BillingSummary | null>(null);
const isLoading = ref(true);
const loadError = ref<string | null>(null);
const payingInvoice = ref<BillingInvoice | null>(null);
const isCreatingQr = ref(false);
let pollTimer: ReturnType<typeof setTimeout> | null = null;
let pollCount = 0;

const subscription = computed(() => summary.value?.subscription ?? null);
const pendingInvoice = computed(() => summary.value?.invoices.find((invoice) => invoice.status === 'pending') ?? null);
const history = computed(() => summary.value?.invoices.filter((invoice) => invoice.status !== 'pending') ?? []);

function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
}

function formatTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' }) : '—';
}

async function load(): Promise<void> {
    try {
        summary.value = (await api.get<{ data: BillingSummary }>('/billing')).data;
        loadError.value = null;
    } catch (e) {
        loadError.value = e instanceof ApiError && e.status === 403
            ? 'บัญชีนี้ไม่มีสิทธิ์ดูค่าแพ็กเกจ กรุณาติดต่อเจ้าของร้าน'
            : e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ';
    } finally {
        isLoading.value = false;
    }
}

async function pay(invoice: BillingInvoice): Promise<void> {
    isCreatingQr.value = true;
    try {
        payingInvoice.value = (await api.post<{ data: BillingInvoice }>(`/billing/invoices/${invoice.id}/pay`)).data;
        pollCount = 0;
        schedulePoll();
    } catch (e) {
        toastError(e);
    } finally {
        isCreatingQr.value = false;
    }
}

function schedulePoll(): void {
    stopPolling();
    pollTimer = setTimeout(poll, POLL_MS);
}

function stopPolling(): void {
    if (pollTimer !== null) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }
}

async function poll(): Promise<void> {
    const invoice = payingInvoice.value;
    if (!invoice) {
        return;
    }
    pollCount++;
    try {
        const refresh = pollCount % REFRESH_EVERY === 0 ? 1 : undefined;
        payingInvoice.value = (await api.get<{ data: BillingInvoice }>(`/billing/invoices/${invoice.id}`, { refresh })).data;
    } catch {
        // เน็ตสะดุดชั่วคราว — รอบถัดไปลองใหม่
    }
    if (payingInvoice.value?.status === 'paid') {
        payingInvoice.value = null;
        toast('ชำระค่าแพ็กเกจแล้ว ขอบคุณครับ', 'success');
        await load();

        return;
    }
    // QR หมดอายุ → ให้กดสร้างใหม่
    if (payingInvoice.value && payingInvoice.value.qr_image_url === null) {
        payingInvoice.value = null;
        toast('QR หมดอายุแล้ว กรุณากดชำระอีกครั้ง', 'info');

        return;
    }
    schedulePoll();
}

onMounted(load);
onBeforeUnmount(stopPolling);
</script>

<template>
    <div class="min-h-dvh bg-slate-100 p-4 sm:p-6">
        <div class="mx-auto max-w-2xl space-y-4">
            <header class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold">แพ็กเกจและการชำระเงิน</h1>
                    <p class="text-sm text-slate-500">ค่าบริการระบบ POS ของร้าน</p>
                </div>
                <RouterLink v-if="summary?.usable" :to="{ name: 'manage' }" class="text-sm font-medium text-teal-700 hover:underline">← กลับหน้าจัดการร้าน</RouterLink>
            </header>

            <p v-if="isLoading" class="text-slate-500">กำลังโหลด…</p>
            <p v-else-if="loadError" class="rounded-2xl bg-white p-4 text-slate-700 ring-1 ring-slate-200">{{ loadError }}</p>

            <template v-else-if="summary">
                <p v-if="!summary.usable" class="rounded-2xl bg-rose-600 p-4 font-semibold text-white" role="alert">
                    ร้านถูกระงับการใช้งานชั่วคราวเพราะค้างชำระค่าแพ็กเกจ — ชำระแล้วใช้งานต่อได้ทันที
                </p>

                <section v-if="subscription" class="space-y-2 rounded-2xl bg-white p-4 ring-1 ring-slate-200">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-lg font-semibold">{{ subscription.plan.name }}</p>
                        <span
                            class="rounded-full px-2.5 py-1 text-xs font-medium"
                            :class="subscription.status === 'past_due' ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700'"
                        >
                            {{ STATUS_LABELS[subscription.status] ?? subscription.status }}
                        </span>
                    </div>
                    <p class="text-sm text-slate-600">
                        {{ subscription.plan.price_satang === 0 ? 'ไม่มีค่าใช้จ่าย' : `${formatBaht(subscription.plan.price_satang)} / ${subscription.plan.billing_interval === 'year' ? 'ปี' : 'เดือน'}` }}
                    </p>
                    <p v-if="subscription.paid_through" class="text-sm text-slate-600">
                        {{ subscription.status === 'trialing' ? 'ทดลองใช้ถึง' : 'ชำระแล้วถึง' }} {{ formatDate(subscription.paid_through) }}
                    </p>
                    <p v-if="subscription.status === 'past_due' && summary.usable" class="text-sm font-medium text-rose-700">
                        กรุณาชำระภายใน {{ formatDate(subscription.grace_ends_at) }} มิฉะนั้นร้านจะถูกระงับการใช้งาน
                    </p>
                </section>
                <p v-else class="rounded-2xl bg-white p-4 text-slate-700 ring-1 ring-slate-200">ร้านยังไม่มีแพ็กเกจ กรุณาติดต่อผู้ให้บริการ</p>

                <section v-if="pendingInvoice" class="space-y-3 rounded-2xl bg-white p-4 ring-1 ring-slate-200">
                    <h2 class="font-semibold">ยอดที่ต้องชำระ</h2>
                    <div class="flex flex-wrap items-end justify-between gap-2">
                        <div>
                            <p class="text-2xl font-semibold">{{ formatBaht(pendingInvoice.amount_satang) }}</p>
                            <p class="text-sm text-slate-500">
                                รอบ {{ formatDate(pendingInvoice.billing_period_start) }} – {{ formatDate(pendingInvoice.billing_period_end) }}
                            </p>
                        </div>
                        <button
                            v-if="payingInvoice?.id !== pendingInvoice.id"
                            type="button"
                            class="btn btn-primary"
                            :disabled="isCreatingQr"
                            @click="pay(pendingInvoice)"
                        >
                            {{ isCreatingQr ? 'กำลังสร้าง QR…' : 'ชำระด้วย PromptPay' }}
                        </button>
                    </div>

                    <div v-if="payingInvoice?.id === pendingInvoice.id && payingInvoice.qr_image_url" class="space-y-2 text-center">
                        <img :src="payingInvoice.qr_image_url" alt="QR PromptPay สำหรับชำระค่าแพ็กเกจ" class="mx-auto w-64 max-w-full rounded-lg ring-1 ring-slate-200" />
                        <p class="text-sm text-slate-600">สแกนด้วยแอปธนาคาร · QR ใช้ได้ถึง {{ formatTime(payingInvoice.qr_expires_at) }}</p>
                        <p class="text-sm text-slate-500" role="status">กำลังรอการชำระเงิน…</p>
                    </div>
                </section>

                <section v-if="history.length > 0" class="space-y-2 rounded-2xl bg-white p-4 ring-1 ring-slate-200">
                    <h2 class="font-semibold">ประวัติการชำระ</h2>
                    <ul class="divide-y divide-slate-100 text-sm">
                        <li v-for="invoice in history" :key="invoice.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span class="text-slate-600">{{ formatDate(invoice.billing_period_start) }} – {{ formatDate(invoice.billing_period_end) }}</span>
                            <span class="font-medium">
                                {{ formatBaht(invoice.amount_satang) }}
                                <span class="font-normal text-slate-500">· {{ invoice.status === 'paid' ? `ชำระ ${formatDate(invoice.paid_at)}` : 'ยกเลิก' }}</span>
                            </span>
                        </li>
                    </ul>
                </section>
            </template>
        </div>
    </div>
</template>
