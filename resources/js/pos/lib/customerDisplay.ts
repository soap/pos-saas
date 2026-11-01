import { effectScope, shallowRef, watch } from 'vue';
import { scopedKey } from '@pos/lib/config';

/**
 * จอฝั่งลูกค้า (customer display) แบบเครื่องเดียว 2 จอ — หน้าขายเป็นผู้ส่ง, หน้า /customer-display แสดงตามอย่างเดียว
 *
 * ส่งผ่าน BroadcastChannel ระหว่างหน้าต่างใน browser เดียวกัน: ไม่ผ่าน server และยังทำงานตอนเน็ตหลุด
 * snapshot มีแค่สิ่งที่ลูกค้าเห็นได้ — ไม่มี token, ชื่อพนักงาน หรือ id ภายใน
 *
 * สถานะที่แสดง = สถานะชำระเงิน (ถ้ามี) ไม่งั้นเป็นสถานะตะกร้า
 * - ตะกร้า: หน้าขายอัปเดตตาม pos store (showBasket)
 * - ชำระเงิน: PaymentDialog ตั้ง qr/paid (showPayment) — paid เปลี่ยนตามผลยืนยันจาก server เท่านั้น
 *   เพราะ QR ที่แสดงไม่ใช่หลักฐานว่าชำระสำเร็จ (ภาคผนวก C)
 */

export interface DisplayLine {
    name: string;
    options: string[];
    quantity: number;
    total_satang: number;
}

export type DisplayState =
    | { stage: 'idle' }
    | { stage: 'cart'; lines: DisplayLine[]; discount_satang: number; total_satang: number; estimated: boolean }
    | { stage: 'qr'; amount_satang: number; qr_payload: string | null; qr_image_url: string | null; requires_slip: boolean }
    | { stage: 'paid'; method: 'cash' | 'qr'; amount_satang: number; change_satang: number | null };

export type DisplaySnapshot = DisplayState & {
    /** หน้าขายที่ส่ง — เปลี่ยนเมื่อหน้าขาย reload (seq เริ่มนับใหม่) */
    source: string;
    seq: number;
    store_name: string | null;
};

type DisplayMessage = { type: 'snapshot'; snapshot: DisplaySnapshot } | { type: 'hello' };

/** หลังชำระสำเร็จ แสดงผลค้างไว้เท่านี้ แล้วกลับไปตามตะกร้า (ออเดอร์ที่ปิดแล้ว = idle) */
export const PAID_HOLD_MS = 8_000;

/** แยกตามร้านที่ปักหมุด — เปิดหลายร้านบนเครื่องเดียวไม่ปนกัน */
const CHANNEL_NAME = scopedKey('pos.customer-display');

function openChannel(): BroadcastChannel | null {
    return typeof BroadcastChannel === 'undefined' ? null : new BroadcastChannel(CHANNEL_NAME);
}

export const isCustomerDisplaySupported = typeof BroadcastChannel !== 'undefined';

// ---------- ฝั่งหน้าขาย ----------

const source = crypto.randomUUID();
let seq = 0;
let channel: BroadcastChannel | null = null;
let latest: DisplaySnapshot | null = null;
let paidTimer: ReturnType<typeof setTimeout> | null = null;

const basket = shallowRef<DisplayState>({ stage: 'idle' });
const payment = shallowRef<DisplayState | null>(null);
const storeName = shallowRef<string | null>(null);

function post(message: DisplayMessage): void {
    channel?.postMessage(message);
}

/** เปิด channel ครั้งเดียวต่อแท็บ และเปิดค้างไว้ — จอลูกค้าที่เพิ่งเปิดจะถาม (hello) แล้วได้สถานะล่าสุด */
function ensureChannel(): void {
    if (channel || !isCustomerDisplaySupported) {
        return;
    }
    channel = openChannel();
    channel?.addEventListener('message', (event: MessageEvent<DisplayMessage>) => {
        if (event.data?.type === 'hello' && latest) {
            post({ type: 'snapshot', snapshot: latest });
        }
    });
    // scope แยก — ไม่ผูกกับ component ที่บังเอิญเรียกครั้งแรก (ออกจากหน้าขายแล้วต้องยังส่งต่อ)
    effectScope(true).run(() =>
        watch(
            [basket, payment, storeName],
            () => {
                latest = { ...(payment.value ?? basket.value), source, seq: ++seq, store_name: storeName.value };
                post({ type: 'snapshot', snapshot: latest });
            },
            { immediate: true },
        ),
    );
}

export function showBasket(state: DisplayState, name: string | null): void {
    ensureChannel();
    basket.value = state;
    storeName.value = name;
}

/** null = กลับไปแสดงตะกร้า; paid จะกลับเองหลัง PAID_HOLD_MS */
export function showPayment(state: DisplayState | null): void {
    ensureChannel();
    if (paidTimer !== null) {
        clearTimeout(paidTimer);
        paidTimer = null;
    }
    payment.value = state;
    if (state?.stage === 'paid') {
        paidTimer = setTimeout(() => showPayment(null), PAID_HOLD_MS);
    }
}

/** ปิด dialog ระหว่างแสดง QR (ยกเลิก/ปิดหน้า) → เลิกแสดง QR แต่ไม่ลบผล paid ที่กำลังแสดงอยู่ */
export function clearPendingPayment(): void {
    if (payment.value?.stage === 'qr') {
        showPayment(null);
    }
}

// ---------- ฝั่งจอลูกค้า ----------

/** รับ snapshot จากหน้าขาย — คืนฟังก์ชันเลิกฟัง; ทิ้ง snapshot ที่เก่ากว่าอันที่แสดงอยู่ */
export function subscribeCustomerDisplay(onSnapshot: (snapshot: DisplaySnapshot) => void): () => void {
    const listener = openChannel();
    if (!listener) {
        return () => {};
    }
    let current: DisplaySnapshot | null = null;
    listener.addEventListener('message', (event: MessageEvent<DisplayMessage>) => {
        if (event.data?.type !== 'snapshot') {
            return;
        }
        const next = event.data.snapshot;
        if (current && current.source === next.source && next.seq <= current.seq) {
            return;
        }
        current = next;
        onSnapshot(next);
    });
    listener.postMessage({ type: 'hello' } satisfies DisplayMessage);

    return () => listener.close();
}

// ---------- เปิดหน้าต่างจอลูกค้า ----------

/** Window Management API (Chrome/Edge) — ยังไม่อยู่ใน lib.dom ของ TypeScript */
interface ScreenDetailed {
    availLeft: number;
    availTop: number;
    availWidth: number;
    availHeight: number;
}
interface ScreenDetails {
    screens: ScreenDetailed[];
    currentScreen: ScreenDetailed;
}

const WINDOW_NAME = 'pos-customer-display';

/**
 * เปิด (หรือโฟกัส) หน้าต่างจอลูกค้า แล้วย้ายไปจอที่ 2 ถ้า browser รองรับและผู้ใช้อนุญาต
 * ต้องเรียกจาก event ที่ผู้ใช้กด — เปิดหน้าต่างก่อน แล้วค่อยขอสิทธิ์จอ ไม่งั้น popup ถูกบล็อก
 * คืน false เมื่อ popup ถูกบล็อก
 */
export async function openCustomerDisplayWindow(url: string): Promise<boolean> {
    const popup = window.open(url, WINDOW_NAME, 'popup,width=1024,height=768');
    if (!popup) {
        return false;
    }
    popup.focus();

    const getScreenDetails = (window as Window & { getScreenDetails?: () => Promise<ScreenDetails> }).getScreenDetails;
    if (!getScreenDetails) {
        return true;
    }
    try {
        const details = await getScreenDetails.call(window);
        const target = details.screens.find((screen) => screen !== details.currentScreen);
        if (target) {
            popup.moveTo(target.availLeft, target.availTop);
            popup.resizeTo(target.availWidth, target.availHeight);
        }
    } catch {
        // ผู้ใช้ไม่อนุญาต — ลากหน้าต่างไปจอที่ 2 เองแล้วกด "เต็มจอ"
    }

    return true;
}
