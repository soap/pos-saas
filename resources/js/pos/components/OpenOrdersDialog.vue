<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { api } from '@pos/api/client';
import type { Order, OrderStatus } from '@pos/api/types';
import BaseModal from '@pos/components/BaseModal.vue';
import { formatBaht } from '@pos/lib/money';
import { toastError } from '@pos/lib/toast';
import { usePosStore } from '@pos/stores/pos';

const emit = defineEmits<{ close: [] }>();

const pos = usePosStore();
/** open = ยังไม่ปิด, closed = ปิดแล้ว/ยกเลิกวันนี้ (ไว้คืนเงินหรือพิมพ์ใบเสร็จซ้ำ) */
const tab = ref<'open' | 'closed'>('open');
const closedOrders = ref<Order[]>([]);
const isLoading = ref(true);
const openingId = ref<string | null>(null);

const statusLabels: Partial<Record<OrderStatus, string>> = { completed: 'ปิดแล้ว', cancelled: 'ยกเลิก' };

async function load(): Promise<void> {
    isLoading.value = true;
    try {
        if (tab.value === 'open') {
            await pos.loadOpenOrders();
        } else {
            closedOrders.value = (await api.get<{ data: Order[] }>('/orders', { status: 'completed,cancelled' })).data;
        }
    } catch (e) {
        toastError(e);
    } finally {
        isLoading.value = false;
    }
}

async function open(id: string): Promise<void> {
    openingId.value = id;
    try {
        await pos.openOrder(id);
        emit('close');
    } catch (e) {
        toastError(e);
    } finally {
        openingId.value = null;
    }
}

watch(tab, load);
onMounted(load);
</script>

<template>
    <BaseModal title="ออเดอร์วันนี้" size="lg" @close="emit('close')">
        <div class="mb-3 grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1" role="tablist">
            <button
                v-for="item in [{ value: 'open', label: 'ยังเปิดอยู่' }, { value: 'closed', label: 'ปิดแล้ว / ยกเลิก' }] as const"
                :key="item.value"
                type="button"
                role="tab"
                class="rounded-lg py-2 text-sm font-medium"
                :class="tab === item.value ? 'bg-white shadow-sm' : 'text-slate-600'"
                :aria-selected="tab === item.value"
                @click="tab = item.value"
            >
                {{ item.label }}
            </button>
        </div>

        <p v-if="isLoading" class="py-8 text-center text-slate-500">กำลังโหลด…</p>
        <template v-else>
            <p v-if="(tab === 'open' ? pos.openOrders : closedOrders).length === 0" class="py-8 text-center text-slate-500">
                {{ tab === 'open' ? 'ไม่มีออเดอร์ค้าง' : 'ยังไม่มีออเดอร์ที่ปิดวันนี้' }}
            </p>
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="item in tab === 'open' ? pos.openOrders : closedOrders" :key="item.id">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-4 rounded-xl px-2 py-3 text-left hover:bg-slate-50 disabled:opacity-50"
                        :class="{ 'bg-teal-50': item.id === pos.order?.id }"
                        :disabled="openingId !== null"
                        @click="open(item.id)"
                    >
                        <span>
                            <span class="block font-semibold">
                                #{{ item.order_number }}<template v-if="item.table_label"> · {{ item.table_label }}</template>
                                <span
                                    v-if="statusLabels[item.status]"
                                    class="ml-1 rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="item.status === 'cancelled' ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700'"
                                >
                                    {{ statusLabels[item.status] }}
                                </span>
                            </span>
                            <span class="block text-sm text-slate-500">
                                {{ item.order_type === 'dine_in' ? 'ทานที่ร้าน' : 'กลับบ้าน' }} ·
                                {{ item.created_at ? new Date(item.created_at).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' }) : '' }}
                            </span>
                        </span>
                        <span class="font-semibold tabular-nums" :class="{ 'text-slate-400 line-through': item.status === 'cancelled' }">{{ formatBaht(item.total_satang) }}</span>
                    </button>
                </li>
            </ul>
        </template>
    </BaseModal>
</template>
