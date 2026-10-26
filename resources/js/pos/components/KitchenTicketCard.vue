<script setup lang="ts">
import { computed } from 'vue';
import type { KitchenTicket } from '@pos/api/types';

const props = defineProps<{
    ticket: KitchenTicket;
    /** เวลา server ปัจจุบัน (ms) */
    nowMs: number;
    lateThresholdMinutes: number;
    isFresh: boolean;
    isBusy: boolean;
    canUpdate: boolean;
    canVoid: boolean;
    /** แสดงชื่อ station บนการ์ด (เมื่อจอแสดงทุกจุดรวมกัน) */
    showStation?: boolean;
}>();
const emit = defineEmits<{ action: [action: 'acknowledge' | 'ready' | 'served']; cancel: [] }>();

const elapsedMinutes = computed(() => {
    if (!props.ticket.sent_at) {
        return 0;
    }

    return Math.max(0, Math.floor((props.nowMs - new Date(props.ticket.sent_at).getTime()) / 60_000));
});
/** เกินเวลาเฉพาะงานที่ยังไม่เสร็จ */
const isLate = computed(() => props.ticket.status !== 'ready' && elapsedMinutes.value >= props.lateThresholdMinutes);

const primary = computed(() => {
    switch (props.ticket.status) {
        case 'queued':
            return { action: 'acknowledge' as const, label: 'รับงาน' };
        case 'preparing':
            return { action: 'ready' as const, label: 'พร้อมเสิร์ฟ' };
        case 'ready':
            return { action: 'served' as const, label: 'เสิร์ฟแล้ว' };
        default:
            return null;
    }
});
</script>

<template>
    <article
        class="flex flex-col overflow-hidden rounded-2xl bg-white shadow-sm ring-2 transition"
        :class="[isLate ? 'ring-rose-500' : isFresh ? 'ring-amber-400' : 'ring-transparent', { 'opacity-60': isBusy }]"
    >
        <header class="flex items-start justify-between gap-2 px-4 pt-3 pb-2" :class="isLate ? 'bg-rose-50' : ''">
            <div class="min-w-0">
                <p class="text-2xl font-bold">#{{ ticket.order_number }}</p>
                <p class="truncate text-sm text-slate-600">
                    {{ ticket.order_type === 'takeaway' ? 'กลับบ้าน' : 'ทานที่ร้าน' }}<template v-if="ticket.table_label"> · {{ ticket.table_label }}</template>
                </p>
            </div>
            <div class="shrink-0 text-right">
                <p class="text-xl font-semibold tabular-nums" :class="isLate ? 'text-rose-700' : 'text-slate-700'">{{ elapsedMinutes }} นาที</p>
                <p v-if="isLate" class="text-xs font-bold text-rose-700">⚠ เกินเวลา</p>
                <p v-else-if="isFresh" class="text-xs font-bold text-amber-700">● ใหม่</p>
            </div>
        </header>

        <p v-if="showStation" class="mx-4 mb-2 inline-block self-start rounded-lg bg-slate-200 px-2 py-0.5 text-sm font-semibold text-slate-700">
            {{ ticket.station?.name ?? 'ครัวหลัก' }}
        </p>

        <p v-if="ticket.is_addition" class="mx-4 mb-2 rounded-lg bg-violet-100 px-2 py-1 text-sm font-semibold text-violet-800">
            ＋ รายการเพิ่ม (รอบที่ {{ ticket.revision }}) — ไม่ต้องทำรายการเดิมซ้ำ
        </p>

        <ul class="flex-1 space-y-2 px-4 pb-3">
            <li v-for="item in ticket.items" :key="item.id" class="text-lg leading-snug">
                <span class="font-bold tabular-nums">{{ item.quantity }}×</span>
                <span class="font-medium"> {{ item.name }}</span>
                <p v-if="item.modifiers?.length" class="pl-7 text-base text-slate-600">
                    {{ item.modifiers.map((m) => (m.quantity > 1 ? `${m.name} ×${m.quantity}` : m.name)).join(', ') }}
                </p>
                <p v-if="item.notes" class="ml-7 rounded bg-amber-100 px-2 py-0.5 text-base font-semibold text-amber-900">📝 {{ item.notes }}</p>
            </li>
        </ul>

        <footer v-if="canUpdate || canVoid" class="flex gap-2 border-t border-slate-100 p-3">
            <button v-if="canVoid" type="button" class="btn btn-secondary px-3 text-sm text-rose-700" :disabled="isBusy" @click="emit('cancel')">ยกเลิก</button>
            <button
                v-if="canUpdate && ticket.status === 'queued'"
                type="button"
                class="btn btn-secondary flex-1"
                :disabled="isBusy"
                @click="emit('action', 'ready')"
            >
                เสร็จเลย
            </button>
            <button v-if="canUpdate && primary" type="button" class="btn btn-primary flex-2 text-lg" :disabled="isBusy" @click="emit('action', primary.action)">
                {{ primary.label }}
            </button>
        </footer>
    </article>
</template>
