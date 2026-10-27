<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import type { KitchenTicketStatus, OrderItem, OrderStatus } from '@pos/api/types';
import { formatBaht } from '@pos/lib/money';
import { shouldPoll, useStoreActivity } from '@pos/lib/realtime';
import { toast, toastError } from '@pos/lib/toast';
import { usePosStore } from '@pos/stores/pos';
import { useSessionStore } from '@pos/stores/session';

const emit = defineEmits<{ pay: []; discount: []; cancel: []; receipt: []; moveTable: []; newOrder: []; refund: [] }>();

const pos = usePosStore();
const session = useSessionStore();
/** ครัวอัปเดตสถานะจากอีกเครื่อง — ดึงออเดอร์ใหม่เป็นระยะขณะยังมีงานค้างในครัว */
const REFRESH_MS = 10_000;

const busyItemId = ref<string | null>(null);
const isSending = ref(false);
const isServing = ref(false);
let refreshTimer: ReturnType<typeof setInterval> | null = null;
let lastRefreshAt = 0;

const statusLabels: Record<OrderStatus, string> = {
    draft: 'ยังไม่ส่งครัว',
    placed: 'ส่งครัวแล้ว',
    preparing: 'ครัวกำลังทำ',
    ready: 'พร้อมเสิร์ฟ',
    served: 'เสิร์ฟแล้ว',
    completed: 'ปิดออเดอร์แล้ว',
    cancelled: 'ยกเลิกแล้ว',
};

const kitchenStatusBadges: Partial<Record<KitchenTicketStatus, { label: string; class: string }>> = {
    queued: { label: 'ส่งคำสั่งแล้ว', class: 'bg-sky-100 text-sky-800' },
    preparing: { label: 'กำลังทำ', class: 'bg-orange-100 text-orange-800' },
    ready: { label: 'พร้อมเสิร์ฟ', class: 'bg-emerald-100 text-emerald-800' },
    served: { label: 'เสิร์ฟแล้ว', class: 'bg-slate-100 text-slate-600' },
};

const order = computed(() => pos.order);
const balanceDue = computed(() => order.value?.balance_due_satang ?? 0);
const canEditItems = computed(() => pos.isOrderOpen && session.can('orders.create'));
const canPay = computed(() => pos.isOrderOpen && balanceDue.value > 0 && pos.lines.length === 0 && session.can('payments.create'));
const canMarkServed = computed(
    () => pos.isOrderOpen && pos.readyTickets.length > 0 && (session.can('orders.create') || session.can('kitchen.update')),
);
const readyItemCount = computed(() => pos.readyTickets.reduce((sum, t) => sum + (t.items?.reduce((n, i) => n + i.quantity, 0) ?? 0), 0));
const canSendToKitchen = computed(
    () => pos.isOrderOpen && session.kitchenEnabled && pos.unsentItemIds.size > 0 && pos.lines.length === 0 && session.can('orders.create'),
);

function kitchenBadge(item: OrderItem): { label: string; class: string } | null {
    const status = pos.itemKitchenStatus.get(item.id);

    return status ? (kitchenStatusBadges[status] ?? null) : null;
}

function modifiersText(item: OrderItem): string {
    return item.modifiers.map((m) => m.name).join(', ');
}

async function runItemAction(itemId: string, action: () => Promise<void>): Promise<void> {
    busyItemId.value = itemId;
    try {
        await action();
    } catch (e) {
        toastError(e);
    } finally {
        busyItemId.value = null;
    }
}

function changeItemQuantity(item: OrderItem, delta: number): Promise<void> {
    const next = item.quantity + delta;

    return runItemAction(item.id, () => (next <= 0 ? pos.removeItem(item.id) : pos.updateItemQuantity(item.id, next)));
}

async function submitDraft(): Promise<void> {
    const isNew = pos.order === null;
    try {
        await pos.submitDraft();
        toast(isNew ? 'บันทึกออเดอร์แล้ว' : 'เพิ่มรายการแล้ว', 'success');
    } catch (e) {
        toastError(e);
    }
}

async function sendToKitchen(): Promise<void> {
    isSending.value = true;
    try {
        await pos.sendToKitchen();
        toast('ส่งรายการเข้าครัวแล้ว', 'success');
    } catch (e) {
        toastError(e);
    } finally {
        isSending.value = false;
    }
}

async function markServed(): Promise<void> {
    isServing.value = true;
    try {
        await pos.markServed();
        toast('บันทึกการเสิร์ฟแล้ว', 'success');
    } catch (e) {
        toastError(e);
    } finally {
        isServing.value = false;
    }
}

async function refreshKitchenStatus(): Promise<void> {
    const isBusy = busyItemId.value !== null || isSending.value || isServing.value || pos.isSubmitting;
    if (isBusy || !pos.isOrderOpen || !pos.hasPendingKitchenWork || document.hidden) {
        return;
    }
    try {
        await pos.refreshOrder();
        lastRefreshAt = Date.now();
    } catch {
        // เน็ตสะดุดชั่วคราว — รอบถัดไปลองใหม่
    }
}

// ออเดอร์ที่เปิดอยู่ถูกเปลี่ยนจากเครื่องอื่น (ครัว/แคชเชียร์อีกจุด) → ดึงใหม่ทันที; null = เพิ่งต่อ realtime ได้
useStoreActivity(
    () => session.storeId,
    () => void refreshKitchenStatus(),
    (activity) => activity.order_id === pos.order?.id,
);

onMounted(() => {
    refreshTimer = setInterval(() => {
        if (shouldPoll(lastRefreshAt)) {
            void refreshKitchenStatus();
        }
    }, REFRESH_MS);
});
onBeforeUnmount(() => {
    if (refreshTimer !== null) {
        clearInterval(refreshTimer);
    }
});
</script>

<template>
    <aside class="flex min-h-0 flex-1 flex-col bg-white">
        <header class="space-y-3 border-b border-slate-200 px-4 py-3">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-lg font-semibold">
                    <template v-if="order">ออเดอร์ #{{ order.order_number }}</template>
                    <template v-else>ออเดอร์ใหม่</template>
                </h2>
                <span
                    v-if="order"
                    class="rounded-full px-2.5 py-1 text-xs font-medium"
                    :class="order.status === 'cancelled' ? 'bg-rose-100 text-rose-700' : order.status === 'completed' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-800'"
                >
                    {{ statusLabels[order.status] }}
                </span>
            </div>

            <div v-if="!order" class="flex gap-2">
                <div class="flex shrink-0 rounded-xl bg-slate-100 p-1" role="radiogroup" aria-label="ประเภทออเดอร์">
                    <button
                        v-for="type in [{ value: 'dine_in', label: 'ทานที่ร้าน' }, { value: 'takeaway', label: 'กลับบ้าน' }] as const"
                        :key="type.value"
                        type="button"
                        role="radio"
                        class="rounded-lg px-3 py-2 text-sm font-medium"
                        :class="pos.orderType === type.value ? 'bg-white shadow-sm' : 'text-slate-600'"
                        :aria-checked="pos.orderType === type.value"
                        @click="pos.orderType = type.value"
                    >
                        {{ type.label }}
                    </button>
                </div>
                <span
                    v-if="pos.orderType === 'dine_in' && pos.selectedTable"
                    class="flex flex-1 items-center justify-between gap-2 rounded-xl bg-teal-50 px-3 text-teal-900 ring-1 ring-teal-200"
                >
                    <span class="font-semibold">โต๊ะ {{ pos.selectedTable.name }}</span>
                    <button type="button" class="rounded-lg px-2 py-1 text-sm hover:bg-teal-100" aria-label="ไม่ใช้โต๊ะนี้" @click="pos.selectedTableId = null">×</button>
                </span>
                <input v-else v-model="pos.tableLabel" type="text" maxlength="32" class="input py-2" placeholder="โต๊ะ / ชื่อลูกค้า" aria-label="โต๊ะหรือชื่อลูกค้า" />
            </div>
            <p v-else class="text-sm text-slate-500">
                {{ order.order_type === 'dine_in' ? 'ทานที่ร้าน' : 'กลับบ้าน' }}<template v-if="order.table_label"> · {{ order.table_label }}</template>
            </p>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto">
            <p v-if="pos.activeItems.length === 0 && pos.lines.length === 0" class="px-4 py-12 text-center text-slate-500">
                แตะเมนูเพื่อเพิ่มรายการ
            </p>

            <ul v-if="pos.activeItems.length > 0" class="divide-y divide-slate-100">
                <li v-for="item in pos.activeItems" :key="item.id" class="flex gap-3 px-4 py-3" :class="{ 'opacity-50': busyItemId === item.id }">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ item.name }}</p>
                        <p v-if="item.modifiers.length" class="text-sm text-slate-500">{{ modifiersText(item) }}</p>
                        <p v-if="item.notes" class="text-sm text-amber-700">หมายเหตุ: {{ item.notes }}</p>
                        <span v-if="session.kitchenEnabled && pos.unsentItemIds.has(item.id)" class="mt-1 inline-block rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800">
                            ยังไม่ส่งครัว
                        </span>
                        <span v-else-if="kitchenBadge(item)" class="mt-1 inline-block rounded px-1.5 py-0.5 text-xs font-medium" :class="kitchenBadge(item)!.class">
                            {{ kitchenBadge(item)!.label }}
                        </span>
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        <span class="font-medium tabular-nums">{{ formatBaht(item.line_total_satang) }}</span>
                        <div v-if="canEditItems && pos.unsentItemIds.has(item.id)" class="flex items-center gap-1">
                            <button type="button" class="size-9 rounded-lg ring-1 ring-slate-300" :disabled="busyItemId !== null" :aria-label="`ลด ${item.name}`" @click="changeItemQuantity(item, -1)">−</button>
                            <span class="w-7 text-center tabular-nums">{{ item.quantity }}</span>
                            <button type="button" class="size-9 rounded-lg ring-1 ring-slate-300" :disabled="busyItemId !== null" :aria-label="`เพิ่ม ${item.name}`" @click="changeItemQuantity(item, 1)">+</button>
                        </div>
                        <span v-else class="text-sm text-slate-500 tabular-nums">× {{ item.quantity }}</span>
                    </div>
                </li>
            </ul>

            <template v-if="pos.lines.length > 0">
                <p class="bg-teal-50 px-4 py-2 text-xs font-semibold text-teal-800">รายการใหม่ (ยังไม่บันทึก)</p>
                <ul class="divide-y divide-slate-100">
                    <li v-for="line in pos.lines" :key="line.key" class="flex gap-3 px-4 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ line.product.name }}</p>
                            <p v-if="line.options.length" class="text-sm text-slate-500">{{ line.options.map((o) => o.name).join(', ') }}</p>
                            <p v-if="line.notes" class="text-sm text-amber-700">หมายเหตุ: {{ line.notes }}</p>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            <span class="font-medium tabular-nums">{{ formatBaht(pos.lineTotal(line)) }}</span>
                            <div class="flex items-center gap-1">
                                <button type="button" class="size-9 rounded-lg ring-1 ring-slate-300" :aria-label="`ลด ${line.product.name}`" @click="pos.changeLineQuantity(line.key, -1)">−</button>
                                <span class="w-7 text-center tabular-nums">{{ line.quantity }}</span>
                                <button type="button" class="size-9 rounded-lg ring-1 ring-slate-300" :aria-label="`เพิ่ม ${line.product.name}`" @click="pos.changeLineQuantity(line.key, 1)">+</button>
                            </div>
                        </div>
                    </li>
                </ul>
            </template>
        </div>

        <footer class="space-y-3 border-t border-slate-200 px-4 py-3">
            <dl v-if="order" class="space-y-1 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500">ยอดรวม</dt><dd class="tabular-nums">{{ formatBaht(order.subtotal_satang) }}</dd></div>
                <div v-if="order.discount_satang > 0" class="flex justify-between">
                    <dt class="text-slate-500">ส่วนลด</dt><dd class="text-rose-600 tabular-nums">−{{ formatBaht(order.discount_satang) }}</dd>
                </div>
                <div v-if="order.tax_satang > 0" class="flex justify-between"><dt class="text-slate-500">VAT</dt><dd class="tabular-nums">{{ formatBaht(order.tax_satang) }}</dd></div>
                <div class="flex justify-between text-base font-semibold"><dt>ยอดสุทธิ</dt><dd class="tabular-nums">{{ formatBaht(order.total_satang) }}</dd></div>
                <div v-if="(order.paid_satang ?? 0) > 0" class="flex justify-between"><dt class="text-slate-500">ชำระแล้ว</dt><dd class="tabular-nums">{{ formatBaht(order.paid_satang ?? 0) }}</dd></div>
                <div v-if="pos.isOrderOpen && balanceDue > 0" class="flex justify-between text-base font-semibold text-teal-800">
                    <dt>ค้างชำระ</dt><dd class="tabular-nums">{{ formatBaht(balanceDue) }}</dd>
                </div>
            </dl>
            <div v-if="(order?.refund_due_satang ?? 0) > 0" class="space-y-2 rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">
                <p>ต้องคืนเงินลูกค้า {{ formatBaht(order?.refund_due_satang ?? 0) }} ก่อนปิดออเดอร์</p>
                <button v-if="session.can('payments.refund')" type="button" class="btn btn-danger min-h-10 w-full py-1.5" @click="emit('refund')">
                    คืนเงิน {{ formatBaht(order?.refund_due_satang ?? 0) }}
                </button>
                <p v-else class="text-xs">ให้ผู้จัดการหรือเจ้าของร้านเป็นผู้คืนเงิน</p>
            </div>
            <p v-if="pos.lines.length > 0" class="flex justify-between text-sm">
                <span class="text-slate-500">รายการใหม่ (ประมาณ)</span>
                <span class="font-medium tabular-nums">{{ formatBaht(pos.draftTotalSatang) }}</span>
            </p>

            <button
                v-if="pos.lines.length > 0"
                type="button"
                class="btn btn-primary w-full"
                :disabled="pos.isSubmitting || (order !== null && !pos.isOrderOpen) || !session.can('orders.create')"
                @click="submitDraft"
            >
                <template v-if="pos.isSubmitting">กำลังบันทึก…</template>
                <template v-else>{{ order ? 'เพิ่มรายการ' : 'บันทึกออเดอร์' }}{{ session.kitchenEnabled ? ' และส่งครัว' : '' }}</template>
            </button>
            <button v-if="canSendToKitchen" type="button" class="btn btn-secondary w-full" :disabled="isSending" @click="sendToKitchen">
                {{ isSending ? 'กำลังส่ง…' : 'ส่งครัว' }}
            </button>
            <button v-if="canMarkServed" type="button" class="btn btn-secondary w-full text-emerald-800 ring-emerald-300" :disabled="isServing" @click="markServed">
                {{ isServing ? 'กำลังบันทึก…' : `เสิร์ฟแล้ว (${readyItemCount} รายการพร้อมเสิร์ฟ)` }}
            </button>
            <button v-if="canPay" type="button" class="btn btn-primary w-full text-lg" @click="emit('pay')">ชำระเงิน · {{ formatBaht(balanceDue) }}</button>

            <div v-if="order" class="flex flex-wrap gap-2">
                <button v-if="pos.isOrderOpen && session.can('orders.discount')" type="button" class="btn btn-secondary flex-1 text-sm" @click="emit('discount')">ส่วนลด</button>
                <button
                    v-if="pos.isOrderOpen && order.order_type === 'dine_in' && pos.tables.length > 0 && session.can('orders.create')"
                    type="button"
                    class="btn btn-secondary flex-1 text-sm"
                    @click="emit('moveTable')"
                >
                    ย้ายโต๊ะ
                </button>
                <button
                    v-if="(order.paid_satang ?? 0) > 0 && (order.refund_due_satang ?? 0) === 0 && session.can('payments.refund')"
                    type="button"
                    class="btn btn-secondary flex-1 text-sm"
                    @click="emit('refund')"
                >
                    คืนเงิน
                </button>
                <button v-if="(order.paid_satang ?? 0) > 0" type="button" class="btn btn-secondary flex-1 text-sm" @click="emit('receipt')">ใบเสร็จ</button>
                <button v-if="pos.isOrderOpen && session.can('orders.cancel')" type="button" class="btn btn-secondary flex-1 text-sm text-rose-700" @click="emit('cancel')">
                    ยกเลิกออเดอร์
                </button>
                <button type="button" class="btn btn-secondary flex-1 text-sm" @click="emit('newOrder')">ออเดอร์ใหม่</button>
            </div>
        </footer>
    </aside>
</template>
