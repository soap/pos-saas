<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { ApiError, api } from '@pos/api/client';
import type { KitchenStation, KitchenTicket } from '@pos/api/types';
import BaseModal from '@pos/components/BaseModal.vue';
import KitchenTicketCard from '@pos/components/KitchenTicketCard.vue';
import { isSoundUnlocked, keepScreenAwake, playChime, releaseScreenAwake, unlockSound } from '@pos/lib/kitchenAlerts';
import { LIVE_FALLBACK_POLL_MS, realtimeState } from '@pos/lib/realtime';
import { storage } from '@pos/lib/storage';
import { toast, toastError } from '@pos/lib/toast';
import { useAuthStore } from '@pos/stores/auth';
import { useKitchenStore } from '@pos/stores/kitchen';
import { usePosStore } from '@pos/stores/pos';
import { useSessionStore } from '@pos/stores/session';

const MUTE_KEY = 'pos.kitchen.muted';
const COLUMNS = [
    { status: 'queued', title: 'รอรับงาน', accent: 'bg-amber-500' },
    { status: 'preparing', title: 'กำลังทำ', accent: 'bg-sky-600' },
    { status: 'ready', title: 'พร้อมเสิร์ฟ', accent: 'bg-emerald-600' },
] as const;

const auth = useAuthStore();
const session = useSessionStore();
const kitchen = useKitchenStore();
const router = useRouter();

const nowMs = ref(Date.now());
const muted = ref(storage.get(MUTE_KEY) === '1');
const soundReady = ref(isSoundUnlocked());
const busyIds = ref<Set<string>>(new Set());
const cancelling = ref<KitchenTicket | null>(null);
const cancelReason = ref('');
const mobileColumn = ref<(typeof COLUMNS)[number]['status']>('queued');
const loadError = ref<string | null>(null);
const stations = ref<KitchenStation[]>([]);
let clock: ReturnType<typeof setInterval> | null = null;

const canUpdate = computed(() => session.can('kitchen.update'));
const canServe = computed(() => session.can('kitchen.update') || session.can('orders.create'));
const canVoid = computed(() => session.can('kitchen.void'));
/** ไม่ได้ sync สำเร็จเกิน 3 รอบ (ขั้นต่ำ 15 วินาที; ขณะ realtime ต่ออยู่ poll ห่างลง จึงนับจากรอบสำรอง) = ข้อมูลอาจไม่เป็นปัจจุบัน */
const isStale = computed(() => {
    const last = kitchen.lastSyncAt;
    const limit = realtimeState.value === 'live' ? LIVE_FALLBACK_POLL_MS * 2 : Math.max(15_000, kitchen.pollIntervalSeconds * 3000);

    return last !== null && nowMs.value - last > limit;
});
const lastSyncText = computed(() =>
    kitchen.lastSyncAt ? new Date(kitchen.lastSyncAt).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '—',
);
const stationLabel = computed(() =>
    kitchen.station === '' ? 'ทุกจุด' : kitchen.station === 'main' ? 'ครัวหลัก' : (stations.value.find((s) => s.id === kitchen.station)?.name ?? 'ทุกจุด'),
);
const serverNowMs = computed(() => nowMs.value + (session.serverNow().getTime() - Date.now()));

function onNewTickets(count: number): void {
    if (!muted.value) {
        playChime();
    }
    toast(`มีออเดอร์ใหม่ ${count} รายการ`, 'info');
}

async function enableSound(): Promise<void> {
    soundReady.value = await unlockSound();
    if (soundReady.value) {
        muted.value = false;
        storage.set(MUTE_KEY, '0');
        playChime();
    } else {
        toast('อุปกรณ์นี้เล่นเสียงไม่ได้', 'error');
    }
}

function toggleMute(): void {
    muted.value = !muted.value;
    storage.set(MUTE_KEY, muted.value ? '1' : '0');
}

async function runAction(ticket: KitchenTicket, action: 'acknowledge' | 'ready' | 'served'): Promise<void> {
    busyIds.value = new Set(busyIds.value).add(ticket.id);
    try {
        await kitchen.transition(ticket.id, action);
    } catch (e) {
        toastError(e);
        // สถานะบนจออาจเก่า (เครื่องอื่นเปลี่ยนไปแล้ว) — ดึงใหม่ทันที
        kitchen.syncNow();
    } finally {
        const next = new Set(busyIds.value);
        next.delete(ticket.id);
        busyIds.value = next;
    }
}

async function confirmCancel(): Promise<void> {
    const ticket = cancelling.value;
    if (!ticket) {
        return;
    }
    busyIds.value = new Set(busyIds.value).add(ticket.id);
    try {
        await kitchen.cancel(ticket.id, cancelReason.value.trim());
        cancelling.value = null;
        cancelReason.value = '';
    } catch (e) {
        toastError(e);
    } finally {
        const next = new Set(busyIds.value);
        next.delete(ticket.id);
        busyIds.value = next;
    }
}

function onVisibilityChange(): void {
    if (document.visibilityState === 'visible') {
        void keepScreenAwake();
        kitchen.syncNow();
    }
}

function onOnline(): void {
    kitchen.syncNow();
}

async function toggleFullscreen(): Promise<void> {
    try {
        if (document.fullscreenElement) {
            await document.exitFullscreen();
        } else {
            await document.documentElement.requestFullscreen();
        }
    } catch {
        // บาง browser (iOS) ไม่รองรับ fullscreen
    }
}

async function logout(): Promise<void> {
    kitchen.reset();
    await auth.logout();
    session.clear();
    usePosStore().reset();
    await router.replace({ name: 'login' });
}

onMounted(async () => {
    try {
        if (!session.isReady) {
            await Promise.all([session.load(), auth.user ? null : auth.fetchMe()]);
        }
    } catch (e) {
        if (e instanceof ApiError && e.status === 403) {
            toastError(e);
            await router.replace({ name: 'stores' });

            return;
        }
        loadError.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ';

        return;
    }
    if (!session.can('kitchen.view')) {
        toast('บัญชีนี้ไม่มีสิทธิ์ดูจอครัว', 'error');
        await router.replace({ name: 'register' });

        return;
    }

    try {
        stations.value = (await api.get<{ data: KitchenStation[] }>('/kitchen-stations')).data;
    } catch {
        stations.value = [];
    }
    kitchen.reset();
    // station ที่จำไว้ถูกปิด/ลบไปแล้ว → กลับไปแสดงทุกจุด
    if (kitchen.station !== '' && kitchen.station !== 'main' && !stations.value.some((s) => s.id === kitchen.station)) {
        kitchen.setStation('');
    }
    kitchen.start(onNewTickets);
    clock = setInterval(() => (nowMs.value = Date.now()), 10_000);
    document.addEventListener('visibilitychange', onVisibilityChange);
    window.addEventListener('online', onOnline);
    void keepScreenAwake();
});

onBeforeUnmount(() => {
    kitchen.stop();
    if (clock !== null) {
        clearInterval(clock);
    }
    document.removeEventListener('visibilitychange', onVisibilityChange);
    window.removeEventListener('online', onOnline);
    void releaseScreenAwake();
});
</script>

<template>
    <div class="flex h-dvh flex-col bg-slate-200">
        <header class="flex flex-wrap items-center gap-2 bg-slate-900 px-3 py-2 text-white sm:px-4">
            <div class="min-w-0 flex-1">
                <p class="truncate text-lg font-semibold">จอครัว · {{ session.context?.store?.name ?? '…' }} · {{ stationLabel }}</p>
                <p class="flex items-center gap-1.5 text-xs" :class="kitchen.connection === 'live' && !isStale ? 'text-emerald-300' : 'text-rose-300'" role="status">
                    <span class="inline-block size-2 rounded-full" :class="kitchen.connection === 'live' && !isStale ? 'bg-emerald-400' : 'bg-rose-400'" aria-hidden="true" />
                    <template v-if="kitchen.connection === 'connecting'">กำลังเชื่อมต่อ…</template>
                    <template v-else-if="kitchen.connection === 'offline'">ขาดการเชื่อมต่อ — กำลังลองใหม่</template>
                    <template v-else-if="realtimeState === 'live'">ออนไลน์ · realtime</template>
                    <template v-else>ออนไลน์</template>
                    · อัปเดตล่าสุด {{ lastSyncText }}
                </p>
            </div>
            <select
                v-if="stations.length > 0"
                :value="kitchen.station"
                class="rounded-lg bg-slate-800 px-3 py-2 text-sm font-medium text-white ring-1 ring-slate-600"
                aria-label="เลือกจุดในครัว"
                @change="kitchen.setStation(($event.target as HTMLSelectElement).value)"
            >
                <option value="">ทุกจุด</option>
                <option value="main">ครัวหลัก</option>
                <option v-for="item in stations" :key="item.id" :value="item.id">{{ item.name }}</option>
            </select>
            <button v-if="!soundReady" type="button" class="rounded-lg bg-amber-500 px-3 py-2 text-sm font-semibold text-slate-900 hover:bg-amber-400" @click="enableSound">
                🔔 เปิดเสียงแจ้งเตือน
            </button>
            <button v-else type="button" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-700" :aria-pressed="muted" @click="toggleMute">
                {{ muted ? '🔇 ปิดเสียงอยู่' : '🔔 เสียงเปิด' }}
            </button>
            <button type="button" class="hidden rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-700 sm:block" @click="toggleFullscreen">เต็มจอ</button>
            <RouterLink v-if="session.can('orders.create')" :to="{ name: 'register' }" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-700">หน้าขาย</RouterLink>
            <button type="button" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-700" @click="logout">ออก</button>
        </header>

        <p v-if="isStale || kitchen.connection === 'offline'" class="bg-rose-600 px-4 py-2 text-center font-semibold text-white" role="alert">
            ⚠ ข้อมูลบนจออาจไม่เป็นปัจจุบัน (อัปเดตล่าสุด {{ lastSyncText }}) — ตรวจสอบอินเทอร์เน็ต
        </p>

        <div v-if="loadError" class="flex flex-1 flex-col items-center justify-center gap-4 p-6 text-center">
            <p class="text-slate-700">{{ loadError }}</p>
            <button type="button" class="btn btn-primary" @click="router.go(0)">ลองอีกครั้ง</button>
        </div>

        <template v-else>
            <nav class="grid grid-cols-3 bg-white lg:hidden" aria-label="สลับคอลัมน์">
                <button
                    v-for="column in COLUMNS"
                    :key="column.status"
                    type="button"
                    class="py-3 font-medium"
                    :class="mobileColumn === column.status ? 'border-b-4 border-slate-900' : 'text-slate-500'"
                    @click="mobileColumn = column.status"
                >
                    {{ column.title }} ({{ kitchen.byStatus[column.status].length }})
                </button>
            </nav>

            <main class="grid min-h-0 flex-1 gap-3 p-3 lg:grid-cols-3">
                <section
                    v-for="column in COLUMNS"
                    :key="column.status"
                    class="min-h-0 flex-col rounded-2xl bg-slate-100"
                    :class="mobileColumn === column.status ? 'flex' : 'hidden lg:flex'"
                    :aria-label="column.title"
                >
                    <h2 class="flex items-center justify-between rounded-t-2xl px-4 py-2 text-lg font-bold text-white" :class="column.accent">
                        {{ column.title }}
                        <span class="rounded-full bg-white/25 px-2.5 text-base tabular-nums">{{ kitchen.byStatus[column.status].length }}</span>
                    </h2>
                    <div class="min-h-0 flex-1 space-y-3 overflow-y-auto p-3">
                        <p v-if="kitchen.byStatus[column.status].length === 0" class="py-10 text-center text-slate-500">ไม่มีรายการ</p>
                        <KitchenTicketCard
                            v-for="ticket in kitchen.byStatus[column.status]"
                            :key="ticket.id"
                            :ticket="ticket"
                            :now-ms="serverNowMs"
                            :late-threshold-minutes="kitchen.lateThresholdMinutes"
                            :is-fresh="kitchen.freshIds.has(ticket.id)"
                            :is-busy="busyIds.has(ticket.id)"
                            :can-update="ticket.status === 'ready' ? canServe : canUpdate"
                            :can-void="canVoid"
                            :show-station="kitchen.station === '' && stations.length > 0"
                            @action="(action) => runAction(ticket, action)"
                            @cancel="cancelling = ticket"
                        />
                    </div>
                </section>
            </main>
        </template>

        <BaseModal v-if="cancelling" :title="`ยกเลิกงานครัว #${cancelling.order_number}`" @close="cancelling = null">
            <form id="kitchen-cancel-form" class="space-y-3" @submit.prevent="confirmCancel">
                <p class="text-sm text-slate-600">รายการใน ticket นี้จะถูกยกเลิกจากออเดอร์ และระบบจะบันทึกเหตุผลไว้</p>
                <div>
                    <label for="kitchen-cancel-reason" class="field-label">เหตุผล (อย่างน้อย 3 ตัวอักษร)</label>
                    <input id="kitchen-cancel-reason" v-model="cancelReason" type="text" minlength="3" maxlength="500" required class="input" placeholder="เช่น วัตถุดิบหมด" />
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-secondary flex-1" @click="cancelling = null">ไม่ยกเลิก</button>
                <button type="submit" form="kitchen-cancel-form" class="btn btn-danger flex-2" :disabled="cancelReason.trim().length < 3 || busyIds.has(cancelling.id)">ยืนยันยกเลิก</button>
            </template>
        </BaseModal>
    </div>
</template>
