<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { ApiError } from '@pos/api/client';
import type { DiningTable, MenuProduct, ModifierOption } from '@pos/api/types';
import CancelOrderDialog from '@pos/components/CancelOrderDialog.vue';
import DiscountDialog from '@pos/components/DiscountDialog.vue';
import MenuPanel from '@pos/components/MenuPanel.vue';
import ModifierDialog from '@pos/components/ModifierDialog.vue';
import MoveTableDialog from '@pos/components/MoveTableDialog.vue';
import OpenOrdersDialog from '@pos/components/OpenOrdersDialog.vue';
import OrderPanel from '@pos/components/OrderPanel.vue';
import PaymentDialog from '@pos/components/PaymentDialog.vue';
import RefundDialog from '@pos/components/RefundDialog.vue';
import ShiftDialog from '@pos/components/ShiftDialog.vue';
import TablesPanel from '@pos/components/TablesPanel.vue';
import { toast, toastError } from '@pos/lib/toast';
import { useAuthStore } from '@pos/stores/auth';
import { usePosStore } from '@pos/stores/pos';
import { useSessionStore } from '@pos/stores/session';

type Dialog = 'payment' | 'shift' | 'discount' | 'cancel' | 'orders' | 'move-table' | 'refund' | null;

const auth = useAuthStore();
const session = useSessionStore();
const pos = usePosStore();
const router = useRouter();

const dialog = ref<Dialog>(null);
const pickedProduct = ref<MenuProduct | null>(null);
/** จอแคบแสดงทีละแผง */
const mobileTab = ref<'menu' | 'order'>('menu');
/** สาขาที่มีโต๊ะเริ่มที่ผังโต๊ะ */
const view = ref<'tables' | 'sell'>('sell');
const loadError = ref<string | null>(null);

const itemCount = computed(() => pos.lines.reduce((sum, l) => sum + l.quantity, 0) + pos.activeItems.reduce((sum, i) => sum + i.quantity, 0));
const canManage = computed(() =>
    ['reports.view', 'catalog.manage', 'members.manage', 'payments.configure', 'stores.manage'].some((permission) => session.can(permission)),
);
const hasTables = computed(() => pos.tables.length > 0);

// เปิดออเดอร์จากที่ใดก็ตาม (ผังโต๊ะ/ออเดอร์ค้าง) → ไปหน้าขาย
watch(
    () => pos.order?.id,
    (id) => {
        if (id) {
            view.value = 'sell';
        }
    },
);
const needsShift = computed(() => session.isReady && session.can('cashier.operate') && session.shift === null);

function pick(product: MenuProduct): void {
    if (product.modifier_groups.length > 0) {
        pickedProduct.value = product;

        return;
    }
    pos.addLine(product, [], null);
}

function addPicked(options: ModifierOption[], notes: string | null, quantity: number): void {
    if (pickedProduct.value) {
        pos.addLine(pickedProduct.value, options, notes, quantity);
    }
    pickedProduct.value = null;
}

async function selectTable(table: DiningTable): Promise<void> {
    if (table.open_order) {
        try {
            await pos.openOrder(table.open_order.id);
        } catch (e) {
            toastError(e);

            return;
        }
    } else {
        pos.startOnTable(table);
    }
    view.value = 'sell';
    mobileTab.value = 'menu';
}

function startNewOrder(): void {
    pos.startNewOrder();
    view.value = hasTables.value ? 'tables' : 'sell';
}

function startTakeaway(): void {
    pos.startNewOrder();
    pos.orderType = 'takeaway';
    view.value = 'sell';
}

async function onPaid(): Promise<void> {
    dialog.value = null;
    toast('ชำระเงินสำเร็จ', 'success');
    const orderId = pos.order?.id;
    await Promise.allSettled([pos.refreshOrder(), session.refreshShift()]);
    if (orderId && (pos.order?.balance_due_satang ?? 0) === 0) {
        await router.push({ name: 'receipt', params: { orderId } });
    }
}

async function onRefunded(): Promise<void> {
    dialog.value = null;
    await Promise.allSettled([pos.refreshOrder(), session.refreshShift()]);
}

async function logout(): Promise<void> {
    await auth.logout();
    session.clear();
    pos.reset();
    await router.replace({ name: 'login' });
}

async function load(): Promise<void> {
    loadError.value = null;
    try {
        // reload หน้า: มี token แต่ยังไม่มีข้อมูล user ใน memory
        await Promise.all([session.load(), auth.user ? null : auth.fetchMe()]);
        // พนักงานครัว (ดูจอครัวได้แต่รับออเดอร์ไม่ได้) → ไปจอครัวเลย
        if (!session.can('orders.create') && session.can('kitchen.view')) {
            await router.replace({ name: 'kitchen' });

            return;
        }
        await Promise.all([pos.loadMenu(), pos.loadTables()]);
        if (!pos.order && pos.lines.length === 0) {
            pos.startNewOrder();
        }
        view.value = hasTables.value && !pos.order && pos.lines.length === 0 ? 'tables' : 'sell';
    } catch (e) {
        if (e instanceof ApiError && e.status === 403) {
            toastError(e);
            await router.replace({ name: 'stores' });

            return;
        }
        loadError.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ';
    }
}

onMounted(load);
</script>

<template>
    <div class="flex h-dvh flex-col">
        <header class="flex items-center gap-2 bg-teal-800 px-3 py-2 text-white sm:px-4">
            <div class="min-w-0 flex-1">
                <p class="truncate font-semibold">{{ session.context?.store?.name ?? 'กำลังโหลด…' }}</p>
                <p class="truncate text-xs text-teal-100">{{ session.context?.tenant.name }} · {{ auth.user?.name }}</p>
            </div>
            <button type="button" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-teal-700" @click="dialog = 'orders'">ออเดอร์</button>
            <button
                v-if="session.can('cashier.operate')"
                type="button"
                class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-teal-700"
                @click="dialog = 'shift'"
            >
                {{ session.shift ? 'ปิดกะ' : 'เปิดกะ' }}
            </button>
            <RouterLink v-if="canManage" :to="{ name: 'manage' }" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-teal-700">จัดการร้าน</RouterLink>
            <RouterLink v-if="session.can('kitchen.view')" :to="{ name: 'kitchen' }" class="hidden rounded-lg px-3 py-2 text-sm font-medium hover:bg-teal-700 sm:block">จอครัว</RouterLink>
            <RouterLink :to="{ name: 'stores' }" class="hidden rounded-lg px-3 py-2 text-sm font-medium hover:bg-teal-700 sm:block">เปลี่ยนสาขา</RouterLink>
            <button type="button" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-teal-700" @click="logout">ออก</button>
        </header>

        <div v-if="loadError" class="flex flex-1 flex-col items-center justify-center gap-4 p-6 text-center">
            <p class="text-slate-600">{{ loadError }}</p>
            <button type="button" class="btn btn-primary" @click="load">ลองอีกครั้ง</button>
        </div>

        <template v-else>
            <button
                v-if="needsShift"
                type="button"
                class="bg-amber-100 px-4 py-2 text-left text-sm text-amber-900 hover:bg-amber-200"
                @click="dialog = 'shift'"
            >
                ยังไม่ได้เปิดกะแคชเชียร์ — รับเงินสดไม่ได้จนกว่าจะเปิดกะ <span class="font-semibold underline">เปิดกะ</span>
            </button>

            <nav v-if="hasTables" class="grid grid-cols-2 gap-1 border-b border-slate-200 bg-slate-50 p-1" aria-label="สลับหน้าจอ">
                <button
                    v-for="item in [{ value: 'tables', label: 'ผังโต๊ะ' }, { value: 'sell', label: pos.order ? `ขาย · #${pos.order.order_number}${pos.order.table_label ? ' ' + pos.order.table_label : ''}` : pos.selectedTable ? `ขาย · โต๊ะ ${pos.selectedTable.name}` : 'ขาย' }] as const"
                    :key="item.value"
                    type="button"
                    class="truncate rounded-lg py-2 font-medium"
                    :class="view === item.value ? 'bg-white shadow-sm' : 'text-slate-600'"
                    :aria-pressed="view === item.value"
                    @click="view = item.value"
                >
                    {{ item.label }}
                </button>
            </nav>

            <main v-if="view === 'tables'" class="flex min-h-0 flex-1">
                <TablesPanel @select="selectTable" @takeaway="startTakeaway" />
            </main>

            <nav v-if="view === 'sell'" class="grid grid-cols-2 border-b border-slate-200 bg-white lg:hidden" aria-label="สลับแผง">
                <button type="button" class="py-3 font-medium" :class="mobileTab === 'menu' ? 'border-b-2 border-teal-700 text-teal-800' : 'text-slate-500'" @click="mobileTab = 'menu'">
                    เมนู
                </button>
                <button type="button" class="py-3 font-medium" :class="mobileTab === 'order' ? 'border-b-2 border-teal-700 text-teal-800' : 'text-slate-500'" @click="mobileTab = 'order'">
                    ออเดอร์<span v-if="itemCount > 0" class="ml-1 rounded-full bg-teal-700 px-2 py-0.5 text-xs text-white">{{ itemCount }}</span>
                </button>
            </nav>

            <main v-if="view === 'sell'" class="flex min-h-0 flex-1">
                <div class="min-h-0 flex-1 flex-col" :class="mobileTab === 'menu' ? 'flex' : 'hidden lg:flex'">
                    <MenuPanel @pick="pick" />
                </div>
                <div class="min-h-0 w-full flex-col border-l border-slate-200 lg:w-96 xl:w-md" :class="mobileTab === 'order' ? 'flex' : 'hidden lg:flex'">
                    <OrderPanel @refund="dialog = 'refund'" @new-order="startNewOrder" @move-table="dialog = 'move-table'" @pay="dialog = 'payment'" @discount="dialog = 'discount'" @cancel="dialog = 'cancel'" @receipt="router.push({ name: 'receipt', params: { orderId: pos.order!.id } })" />
                </div>
            </main>
        </template>

        <ModifierDialog v-if="pickedProduct" :product="pickedProduct" @close="pickedProduct = null" @add="addPicked" />
        <PaymentDialog v-if="dialog === 'payment' && pos.order" :order="pos.order" @close="dialog = null" @paid="onPaid" />
        <ShiftDialog v-if="dialog === 'shift'" @close="dialog = null" />
        <DiscountDialog v-if="dialog === 'discount'" @close="dialog = null" />
        <CancelOrderDialog v-if="dialog === 'cancel'" @close="dialog = null" />
        <OpenOrdersDialog v-if="dialog === 'orders'" @close="dialog = null" />
        <MoveTableDialog v-if="dialog === 'move-table'" @close="dialog = null" />
        <RefundDialog v-if="dialog === 'refund' && pos.order" :order="pos.order" @close="dialog = null" @refunded="onRefunded" />
    </div>
</template>
