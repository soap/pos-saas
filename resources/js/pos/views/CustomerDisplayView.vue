<script setup lang="ts">
import QRCode from 'qrcode';
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { type DisplaySnapshot, isCustomerDisplaySupported, PAID_HOLD_MS, subscribeCustomerDisplay } from '@pos/lib/customerDisplay';
import { keepScreenAwake, releaseScreenAwake } from '@pos/lib/kitchenAlerts';
import { formatBaht } from '@pos/lib/money';

/** ยังไม่ได้รับอะไรจากหน้าขายภายในเวลานี้ → บอกว่ารอเชื่อมต่อ */
const WAITING_HINT_MS = 2_000;

const snapshot = ref<DisplaySnapshot | null>(null);
const qrImage = ref<string | null>(null);
const showWaitingHint = ref(false);
const isFullscreen = ref(document.fullscreenElement !== null);
const lineList = ref<HTMLElement | null>(null);

let unsubscribe: (() => void) | null = null;
let waitingTimer: ReturnType<typeof setTimeout> | null = null;
let paidTimer: ReturnType<typeof setTimeout> | null = null;

function receive(next: DisplaySnapshot): void {
    showWaitingHint.value = false;
    snapshot.value = next;
}

watch(snapshot, async (current) => {
    if (paidTimer !== null) {
        clearTimeout(paidTimer);
        paidTimer = null;
    }
    // สำรองกรณีหน้าขายถูกปิดระหว่างแสดงผลชำระ — กลับหน้า idle เอง
    if (current?.stage === 'paid') {
        paidTimer = setTimeout(() => (snapshot.value = { ...current, stage: 'idle' }), PAID_HOLD_MS + 2_000);
    }

    qrImage.value = null;
    if (current?.stage === 'qr') {
        const image = current.qr_payload ? await QRCode.toDataURL(current.qr_payload, { width: 480, margin: 1 }) : current.qr_image_url;
        // ระหว่างสร้างรูปอาจมี snapshot ใหม่มาแล้ว — อย่าแสดง QR ของรอบก่อน
        if (snapshot.value === current) {
            qrImage.value = image;
        }
    }

    // รายการล่าสุดอยู่ท้ายรายการ — เลื่อนให้ลูกค้าเห็นของที่เพิ่งเพิ่ม
    if (current?.stage === 'cart') {
        await nextTick();
        lineList.value?.scrollTo({ top: lineList.value.scrollHeight, behavior: 'smooth' });
    }
});

async function enterFullscreen(): Promise<void> {
    try {
        await document.documentElement.requestFullscreen();
    } catch {
        // browser/kiosk ไม่อนุญาต — ใช้ F11 หรือเปิด Chrome แบบ --kiosk แทน
    }
}

function onFullscreenChange(): void {
    isFullscreen.value = document.fullscreenElement !== null;
}

function onVisibilityChange(): void {
    void keepScreenAwake();
}

onMounted(() => {
    document.title = 'จอลูกค้า';
    unsubscribe = subscribeCustomerDisplay(receive);
    waitingTimer = setTimeout(() => (showWaitingHint.value = snapshot.value === null), WAITING_HINT_MS);
    void keepScreenAwake();
    document.addEventListener('visibilitychange', onVisibilityChange);
    document.addEventListener('fullscreenchange', onFullscreenChange);
});

onBeforeUnmount(() => {
    unsubscribe?.();
    [waitingTimer, paidTimer].forEach((timer) => timer !== null && clearTimeout(timer));
    document.removeEventListener('visibilitychange', onVisibilityChange);
    document.removeEventListener('fullscreenchange', onFullscreenChange);
    void releaseScreenAwake();
});
</script>

<template>
    <div class="relative flex h-dvh flex-col bg-slate-50 select-none">
        <header class="flex items-center justify-between bg-teal-800 px-6 py-4 text-white">
            <p class="truncate text-2xl font-semibold">{{ snapshot?.store_name ?? '' }}</p>
            <button v-if="!isFullscreen" type="button" class="rounded-lg px-3 py-2 text-sm text-teal-100 hover:bg-teal-700" @click="enterFullscreen">
                เต็มจอ
            </button>
        </header>

        <!-- รอรายการ -->
        <main v-if="!snapshot || snapshot.stage === 'idle'" class="flex flex-1 flex-col items-center justify-center gap-4 p-8 text-center">
            <p class="text-5xl font-bold text-teal-800">ยินดีต้อนรับ</p>
            <p v-if="snapshot?.store_name" class="text-2xl text-slate-600">{{ snapshot.store_name }}</p>
            <p v-if="!isCustomerDisplaySupported" class="mt-6 text-slate-500" role="alert">browser นี้ไม่รองรับจอลูกค้า — กรุณาใช้ Chrome หรือ Edge รุ่นล่าสุด</p>
            <p v-else-if="showWaitingHint" class="mt-6 text-slate-500">รอการเชื่อมต่อกับหน้าขาย… (เปิดจอนี้จากปุ่ม "จอลูกค้า" ในหน้าขาย)</p>
        </main>

        <!-- รายการสินค้า -->
        <main v-else-if="snapshot.stage === 'cart'" class="flex min-h-0 flex-1 flex-col lg:flex-row">
            <ul ref="lineList" class="min-h-0 flex-1 divide-y divide-slate-200 overflow-y-auto px-6 py-2">
                <li v-for="(line, index) in snapshot.lines" :key="index" class="flex items-start gap-4 py-4 text-2xl">
                    <span class="w-12 shrink-0 text-right font-semibold text-teal-800 tabular-nums">{{ line.quantity }}×</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ line.name }}</p>
                        <p v-if="line.options.length > 0" class="text-lg text-slate-500">{{ line.options.join(', ') }}</p>
                    </div>
                    <span class="shrink-0 tabular-nums">{{ formatBaht(line.total_satang) }}</span>
                </li>
            </ul>
            <aside class="flex flex-col justify-center gap-3 border-t border-slate-200 bg-white px-6 py-6 lg:w-md lg:border-t-0 lg:border-l">
                <p v-if="snapshot.discount_satang > 0" class="flex justify-between text-2xl text-emerald-700">
                    <span>ส่วนลด</span>
                    <span class="tabular-nums">−{{ formatBaht(snapshot.discount_satang) }}</span>
                </p>
                <p class="text-2xl text-slate-500">ยอดรวม</p>
                <p class="text-right text-6xl font-bold text-teal-800 tabular-nums">{{ formatBaht(snapshot.total_satang) }}</p>
                <p v-if="snapshot.estimated" class="text-right text-base text-slate-400">ยอดโดยประมาณ ยอดจริงยืนยันเมื่อพนักงานบันทึกรายการ</p>
            </aside>
        </main>

        <!-- QR พร้อมเพย์ -->
        <main v-else-if="snapshot.stage === 'qr'" class="flex flex-1 flex-col items-center justify-center gap-6 p-6 text-center">
            <div>
                <p class="text-2xl text-slate-500">ยอดที่ต้องชำระ</p>
                <p class="text-6xl font-bold text-teal-800 tabular-nums">{{ formatBaht(snapshot.amount_satang) }}</p>
            </div>
            <img v-if="qrImage" :src="qrImage" alt="QR พร้อมเพย์สำหรับชำระเงิน" class="aspect-square w-full max-w-sm rounded-2xl bg-white p-3 ring-1 ring-slate-200" />
            <p class="text-2xl font-medium">สแกนด้วยแอปธนาคารเพื่อชำระเงิน</p>
            <p v-if="snapshot.requires_slip" class="text-xl text-slate-600">โอนแล้วกรุณาแสดงสลิปให้พนักงาน</p>
        </main>

        <!-- ชำระสำเร็จ -->
        <main v-else-if="snapshot.stage === 'paid'" class="flex flex-1 flex-col items-center justify-center gap-4 p-8 text-center">
            <svg class="size-28 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="12" cy="12" r="10" />
                <path d="m7.5 12.5 3 3 6-6.5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <p class="text-5xl font-bold text-emerald-700">ชำระเงินเรียบร้อย</p>
            <p class="text-3xl tabular-nums">{{ formatBaht(snapshot.amount_satang) }}</p>
            <p v-if="snapshot.method === 'cash' && (snapshot.change_satang ?? 0) > 0" class="text-3xl">
                เงินทอน <span class="font-bold tabular-nums">{{ formatBaht(snapshot.change_satang ?? 0) }}</span>
            </p>
            <p class="mt-4 text-2xl text-slate-600">ขอบคุณที่ใช้บริการ</p>
        </main>
    </div>
</template>
