<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import type { DiningTable } from '@pos/api/types';
import { formatBaht } from '@pos/lib/money';
import { toastError } from '@pos/lib/toast';
import { usePosStore } from '@pos/stores/pos';
import { useSessionStore } from '@pos/stores/session';

const emit = defineEmits<{ select: [table: DiningTable]; takeaway: [] }>();

/** เครื่องอื่นอาจเปิด/ปิดโต๊ะ — ดึงผังใหม่เป็นระยะขณะหน้านี้เปิดอยู่ */
const REFRESH_MS = 15_000;

const pos = usePosStore();
const session = useSessionStore();
const nowMs = ref(Date.now());
const isLoading = ref(pos.tables.length === 0);
let timer: ReturnType<typeof setInterval> | null = null;

const zones = computed(() => {
    const groups = new Map<string, DiningTable[]>();
    for (const table of pos.tables) {
        const zone = table.zone?.trim() || 'ทั่วไป';
        groups.set(zone, [...(groups.get(zone) ?? []), table]);
    }

    return [...groups.entries()].map(([name, tables]) => ({ name, tables }));
});
const freeCount = computed(() => pos.tables.filter((t) => !t.open_order).length);

function elapsedMinutes(table: DiningTable): number | null {
    const createdAt = table.open_order?.created_at;
    if (!createdAt) {
        return null;
    }
    const serverNow = nowMs.value + (session.serverNow().getTime() - Date.now());

    return Math.max(0, Math.floor((serverNow - new Date(createdAt).getTime()) / 60_000));
}

async function refresh(): Promise<void> {
    try {
        await pos.loadTables();
        nowMs.value = Date.now();
    } catch (e) {
        toastError(e);
    } finally {
        isLoading.value = false;
    }
}

onMounted(() => {
    void refresh();
    timer = setInterval(refresh, REFRESH_MS);
});
onBeforeUnmount(() => {
    if (timer !== null) {
        clearInterval(timer);
    }
});
</script>

<template>
    <section class="flex min-h-0 flex-1 flex-col">
        <div class="flex items-center justify-between gap-2 border-b border-slate-200 bg-white px-4 py-3">
            <p class="text-sm text-slate-600">
                ว่าง <span class="font-semibold text-emerald-700">{{ freeCount }}</span> / {{ pos.tables.length }} โต๊ะ
            </p>
            <div class="flex gap-2">
                <button type="button" class="btn btn-secondary min-h-10 py-1.5 text-sm" @click="refresh">รีเฟรช</button>
                <button type="button" class="btn btn-primary min-h-10 py-1.5 text-sm" @click="emit('takeaway')">+ กลับบ้าน</button>
            </div>
        </div>

        <div class="min-h-0 flex-1 space-y-6 overflow-y-auto p-4">
            <p v-if="isLoading" class="text-slate-500">กำลังโหลดผังโต๊ะ…</p>
            <section v-for="zone in zones" :key="zone.name">
                <h2 class="mb-2 text-sm font-semibold tracking-wide text-slate-500">{{ zone.name }}</h2>
                <ul class="grid grid-cols-3 gap-3 sm:grid-cols-4 xl:grid-cols-6">
                    <li v-for="table in zone.tables" :key="table.id">
                        <button
                            type="button"
                            class="flex aspect-square w-full flex-col justify-between rounded-2xl p-3 text-left ring-2 transition active:scale-[0.97]"
                            :class="
                                table.open_order
                                    ? 'bg-amber-50 ring-amber-400 hover:bg-amber-100'
                                    : 'bg-white ring-slate-200 hover:ring-teal-600'
                            "
                            :aria-label="table.open_order ? `โต๊ะ ${table.name} ไม่ว่าง ออเดอร์ #${table.open_order.order_number}` : `โต๊ะ ${table.name} ว่าง`"
                            @click="emit('select', table)"
                        >
                            <span class="flex items-start justify-between gap-1">
                                <span class="text-xl font-bold">{{ table.name }}</span>
                                <span v-if="table.seats" class="text-xs text-slate-500">{{ table.seats }} ที่</span>
                            </span>
                            <span v-if="table.open_order" class="text-sm">
                                <span class="block font-medium text-amber-900">#{{ table.open_order.order_number }} · {{ elapsedMinutes(table) }} นาที</span>
                                <span class="block font-semibold tabular-nums">{{ formatBaht(table.open_order.total_satang) }}</span>
                            </span>
                            <span v-else class="text-sm font-medium text-emerald-700">ว่าง</span>
                        </button>
                    </li>
                </ul>
            </section>
        </div>
    </section>
</template>
