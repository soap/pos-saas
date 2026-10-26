<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { api } from '@pos/api/client';
import type { DailySalesReport, ProductSalesReport } from '@pos/api/types';
import { formatBaht } from '@pos/lib/money';
import { errorMessage } from '@pos/lib/toast';
import { useSessionStore } from '@pos/stores/session';

const session = useSessionStore();

/** วันที่ตามเวลา server ใน timezone ของสาขา (ไม่ใช้นาฬิกาเครื่อง) */
function storeDate(offsetDays = 0): string {
    const date = new Date(session.serverNow().getTime() + offsetDays * 86_400_000);

    return new Intl.DateTimeFormat('en-CA', { timeZone: session.context?.store?.timezone ?? 'Asia/Bangkok' }).format(date);
}

const from = ref(storeDate());
const to = ref(storeDate());
const daily = ref<DailySalesReport | null>(null);
const products = ref<ProductSalesReport | null>(null);
const isLoading = ref(false);
const error = ref<string | null>(null);

const totals = computed(() =>
    (daily.value?.days ?? []).reduce(
        (sum, day) => ({
            orders: sum.orders + day.completed_orders,
            cancelled: sum.cancelled + day.cancelled_orders,
            total: sum.total + day.total_satang,
            discount: sum.discount + day.discount_satang,
            cash: sum.cash + day.payments.cash,
            qr: sum.qr + day.payments.qr,
            refunds: sum.refunds + day.refunds_satang,
        }),
        { orders: 0, cancelled: 0, total: 0, discount: 0, cash: 0, qr: 0, refunds: 0 },
    ),
);

function setRange(days: number): void {
    from.value = storeDate(-(days - 1));
    to.value = storeDate();
    void load();
}

async function load(): Promise<void> {
    isLoading.value = true;
    error.value = null;
    try {
        const params = { from: from.value, to: to.value };
        const [dailyResponse, productResponse] = await Promise.all([
            api.get<{ data: DailySalesReport }>('/reports/sales/daily', params),
            api.get<{ data: ProductSalesReport }>('/reports/sales/products', params),
        ]);
        daily.value = dailyResponse.data;
        products.value = productResponse.data;
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        isLoading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="mx-auto max-w-5xl space-y-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">รายงานยอดขาย</h1>
                <p class="text-sm text-slate-500">สาขา {{ session.context?.store?.name }} · นับเฉพาะออเดอร์ที่ปิดแล้ว</p>
            </div>
            <form class="flex flex-wrap items-end gap-2" @submit.prevent="load">
                <div>
                    <label for="report-from" class="field-label">ตั้งแต่</label>
                    <input id="report-from" v-model="from" type="date" required class="input py-2" />
                </div>
                <div>
                    <label for="report-to" class="field-label">ถึง</label>
                    <input id="report-to" v-model="to" type="date" required :min="from" class="input py-2" />
                </div>
                <button type="submit" class="btn btn-primary" :disabled="isLoading">ดูรายงาน</button>
            </form>
        </header>

        <div class="flex flex-wrap gap-2">
            <button type="button" class="btn btn-secondary min-h-10 py-1.5 text-sm" @click="setRange(1)">วันนี้</button>
            <button type="button" class="btn btn-secondary min-h-10 py-1.5 text-sm" @click="setRange(7)">7 วัน</button>
            <button type="button" class="btn btn-secondary min-h-10 py-1.5 text-sm" @click="setRange(30)">30 วัน</button>
        </div>

        <p v-if="error" class="rounded-xl bg-rose-50 px-4 py-3 text-rose-700" role="alert">{{ error }}</p>

        <template v-else-if="daily">
            <section class="grid grid-cols-2 gap-3 md:grid-cols-4" aria-label="สรุป">
                <div class="rounded-2xl bg-white p-4 ring-1 ring-slate-200">
                    <p class="text-sm text-slate-500">ยอดขายสุทธิ</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">{{ formatBaht(totals.total) }}</p>
                </div>
                <div class="rounded-2xl bg-white p-4 ring-1 ring-slate-200">
                    <p class="text-sm text-slate-500">ออเดอร์ที่ปิดแล้ว</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">{{ totals.orders.toLocaleString('th-TH') }}</p>
                    <p class="text-xs text-slate-500">ยกเลิก {{ totals.cancelled }}</p>
                </div>
                <div class="rounded-2xl bg-white p-4 ring-1 ring-slate-200">
                    <p class="text-sm text-slate-500">รับเงินสด / QR</p>
                    <p class="mt-1 text-lg font-semibold tabular-nums">{{ formatBaht(totals.cash) }}</p>
                    <p class="text-lg font-semibold tabular-nums">{{ formatBaht(totals.qr) }}</p>
                </div>
                <div class="rounded-2xl bg-white p-4 ring-1 ring-slate-200">
                    <p class="text-sm text-slate-500">ส่วนลด / คืนเงิน</p>
                    <p class="mt-1 text-lg font-semibold tabular-nums">{{ formatBaht(totals.discount) }}</p>
                    <p class="text-lg font-semibold text-rose-700 tabular-nums">{{ formatBaht(totals.refunds) }}</p>
                </div>
            </section>

            <section class="overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200">
                <h2 class="border-b border-slate-200 px-4 py-3 font-semibold">รายวัน</h2>
                <p v-if="daily.days.length === 0" class="px-4 py-8 text-center text-slate-500">ไม่มียอดขายในช่วงนี้</p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left text-slate-600">
                            <tr>
                                <th class="px-4 py-2 font-medium">วันที่</th>
                                <th class="px-4 py-2 text-right font-medium">ออเดอร์</th>
                                <th class="px-4 py-2 text-right font-medium">เงินสด</th>
                                <th class="px-4 py-2 text-right font-medium">QR</th>
                                <th class="px-4 py-2 text-right font-medium">คืนเงิน</th>
                                <th class="px-4 py-2 text-right font-medium">ยอดสุทธิ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="day in daily.days" :key="day.business_date">
                                <td class="px-4 py-2 whitespace-nowrap">{{ day.business_date }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ day.completed_orders }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ formatBaht(day.payments.cash) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ formatBaht(day.payments.qr) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ formatBaht(day.refunds_satang) }}</td>
                                <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ formatBaht(day.total_satang) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200">
                <h2 class="border-b border-slate-200 px-4 py-3 font-semibold">สินค้าขายดี</h2>
                <p v-if="!products?.products.length" class="px-4 py-8 text-center text-slate-500">ไม่มีข้อมูล</p>
                <ol v-else class="divide-y divide-slate-100">
                    <li v-for="(row, index) in products.products" :key="`${row.product_id}-${row.name}`" class="flex items-center gap-3 px-4 py-2 text-sm">
                        <span class="w-6 text-right text-slate-400 tabular-nums">{{ index + 1 }}</span>
                        <span class="flex-1">{{ row.name }}</span>
                        <span class="text-slate-500 tabular-nums">× {{ row.quantity }}</span>
                        <span class="w-28 text-right font-medium tabular-nums">{{ formatBaht(row.revenue_satang) }}</span>
                    </li>
                </ol>
            </section>
        </template>
    </div>
</template>
