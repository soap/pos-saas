<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '@pos/api/client';
import type { Receipt } from '@pos/api/types';
import { errorMessage } from '@pos/lib/toast';
import { usePosStore } from '@pos/stores/pos';

const props = defineProps<{ orderId: string }>();

const pos = usePosStore();
const router = useRouter();
const receipt = ref<Receipt | null>(null);
const error = ref<string | null>(null);

/** กลับหน้าขาย — ถ้าออเดอร์นี้ปิดแล้ว เริ่มออเดอร์ใหม่ทันที */
async function back(): Promise<void> {
    if (pos.order?.id === props.orderId && !pos.isOrderOpen) {
        pos.startNewOrder();
    }
    await router.push({ name: 'register' });
}

function print(): void {
    window.print();
}

onMounted(async () => {
    try {
        receipt.value = (await api.get<{ data: Receipt }>(`/orders/${props.orderId}/receipt`)).data;
    } catch (e) {
        error.value = errorMessage(e);
    }
});
</script>

<template>
    <main class="flex min-h-full flex-col items-center gap-4 p-4 print:block print:p-0">
        <div class="flex w-full max-w-xs gap-2 print:hidden">
            <button type="button" class="btn btn-secondary flex-1" @click="back">กลับไปหน้าขาย</button>
            <button type="button" class="btn btn-primary flex-1" :disabled="!receipt" @click="print">พิมพ์</button>
        </div>

        <p v-if="error" class="text-rose-600">{{ error }}</p>
        <p v-else-if="!receipt" class="text-slate-500">กำลังโหลดใบเสร็จ…</p>

        <article v-else class="receipt w-full max-w-xs bg-white p-4 font-mono text-sm shadow-sm ring-1 ring-slate-200 print:max-w-none print:shadow-none print:ring-0">
            <header class="text-center">
                <h1 class="text-base font-bold">{{ receipt.store.name }}</h1>
                <p>ออเดอร์ #{{ receipt.order_number }} · {{ receipt.order_type === 'dine_in' ? 'ทานที่ร้าน' : 'กลับบ้าน' }}</p>
                <p v-if="receipt.table_label">{{ receipt.table_label }}</p>
                <p>{{ receipt.printed_at }}</p>
            </header>

            <hr class="my-2 border-dashed border-slate-400" />

            <ul class="space-y-1">
                <li v-for="(line, index) in receipt.lines" :key="index">
                    <div class="flex justify-between gap-2">
                        <span>{{ line.quantity }} × {{ line.name }}</span>
                        <span class="shrink-0 tabular-nums">{{ line.line_total }}</span>
                    </div>
                    <p v-if="line.modifiers.length" class="pl-4 text-xs text-slate-600">{{ line.modifiers.join(', ') }}</p>
                </li>
            </ul>

            <hr class="my-2 border-dashed border-slate-400" />

            <dl class="space-y-0.5">
                <div class="flex justify-between"><dt>รวม</dt><dd class="tabular-nums">{{ receipt.subtotal }}</dd></div>
                <div v-if="receipt.discount !== '0.00'" class="flex justify-between"><dt>ส่วนลด</dt><dd class="tabular-nums">-{{ receipt.discount }}</dd></div>
                <template v-if="receipt.tax_included">
                    <div class="flex justify-between"><dt>มูลค่าก่อน VAT</dt><dd class="tabular-nums">{{ receipt.amount_before_tax }}</dd></div>
                    <div class="flex justify-between"><dt>VAT</dt><dd class="tabular-nums">{{ receipt.tax }}</dd></div>
                </template>
                <div class="flex justify-between text-base font-bold"><dt>ยอดสุทธิ</dt><dd class="tabular-nums">{{ receipt.total }}</dd></div>
            </dl>

            <hr class="my-2 border-dashed border-slate-400" />

            <dl class="space-y-0.5">
                <template v-for="(payment, index) in receipt.payments" :key="index">
                    <div class="flex justify-between"><dt>{{ payment.method === 'cash' ? 'เงินสด' : 'QR พร้อมเพย์' }}</dt><dd class="tabular-nums">{{ payment.amount }}</dd></div>
                    <div v-if="payment.tendered" class="flex justify-between text-xs"><dt>รับมา</dt><dd class="tabular-nums">{{ payment.tendered }}</dd></div>
                    <div v-if="payment.change" class="flex justify-between text-xs"><dt>เงินทอน</dt><dd class="tabular-nums">{{ payment.change }}</dd></div>
                </template>
            </dl>

            <p class="mt-3 text-center">ขอบคุณที่ใช้บริการ</p>
        </article>
    </main>
</template>

<style scoped>
@media print {
    @page {
        size: 80mm auto;
        margin: 4mm;
    }
}
</style>
