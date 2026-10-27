import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { api } from '@pos/api/client';
import { posConfig } from '@pos/lib/config';

/**
 * สัญญาณ realtime จาก Reverb (ภาคผนวก D3 Phase 2) — private channel ต่อสาขา `store.{storeId}`
 *
 * server ส่งแค่ "ออเดอร์ไหนเปลี่ยน" แล้วหน้าจอดึงข้อมูลล่าสุดจาก API เอง
 * listener ได้ `null` เมื่อเพิ่ง subscribe สำเร็จ (ครั้งแรกหรือหลังหลุดแล้วต่อใหม่) → ให้ sync ทันที
 * เพราะระหว่างหลุดอาจพลาดสัญญาณไป
 *
 * polling ของแต่ละหน้ายังอยู่เป็นทางสำรอง: ขณะ `realtimeState === 'live'` ให้ poll ห่างลงได้
 */

export interface OrderActivity {
    order_id: string;
    event_type: string;
    payment_id: string | null;
}

export type OrderActivityListener = (activity: OrderActivity | null) => void;

/** disabled = server ไม่เปิด realtime, live = subscribe channel แล้ว, down = ต่อไม่ได้/หลุด (pusher-js ต่อใหม่เอง) */
export type RealtimeState = 'disabled' | 'connecting' | 'live' | 'down';

/** ขณะ live ยัง poll ห่าง ๆ ไว้ เผื่อสัญญาณหาย (worker ค้าง, event ตกหล่น) */
export const LIVE_FALLBACK_POLL_MS = 60_000;
const COALESCE_MS = 250;

export const realtimeState = ref<RealtimeState>(posConfig.realtime ? 'connecting' : 'disabled');

const listeners = new Set<OrderActivityListener>();
let echo: Echo<'reverb'> | null = null;
let joinedStoreId: string | null = null;

function connect(): Echo<'reverb'> | null {
    const config = posConfig.realtime;
    if (!config) {
        return null;
    }
    if (echo) {
        return echo;
    }
    echo = new Echo({
        broadcaster: 'reverb',
        Pusher,
        key: config.key,
        wsHost: config.host,
        wsPort: config.port,
        wssPort: config.port,
        forceTLS: config.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        // ขอสิทธิ์ผ่าน api client — ได้ Bearer token, tenant (/t/{slug} หรือ X-Tenant-Id) และ X-Store-Id ครบ
        channelAuthorization: {
            endpoint: '',
            transport: 'ajax',
            customHandler: ({ socketId, channelName }, callback) => {
                api.post<{ auth: string }>('/broadcasting/auth', { socket_id: socketId, channel_name: channelName })
                    .then((data) => callback(null, data))
                    .catch((error: Error) => callback(error, null));
            },
        },
    });
    echo.connector.pusher.connection.bind('state_change', ({ current }: { current: string }) => {
        if (current !== 'connected') {
            realtimeState.value = current === 'connecting' || current === 'initialized' ? 'connecting' : 'down';
        }
    });

    return echo;
}

function join(storeId: string): void {
    const client = connect();
    if (!client || joinedStoreId === storeId) {
        return;
    }
    leave();
    joinedStoreId = storeId;
    client
        .private(`store.${storeId}`)
        .subscribed(() => {
            realtimeState.value = 'live';
            listeners.forEach((listener) => listener(null));
        })
        .error(() => {
            realtimeState.value = 'down';
        })
        .listen('.order.changed', (activity: OrderActivity) => {
            listeners.forEach((listener) => listener(activity));
        });
}

function leave(): void {
    if (echo && joinedStoreId) {
        echo.leave(`store.${joinedStoreId}`);
    }
    joinedStoreId = null;
    if (posConfig.realtime) {
        realtimeState.value = 'connecting';
    }
}

/** รับสัญญาณของสาขา — คืนฟังก์ชันยกเลิก; ไม่มีผู้ฟังเหลือแล้วจะ leave channel */
export function onStoreActivity(storeId: string, listener: OrderActivityListener): () => void {
    listeners.add(listener);
    join(storeId);

    return () => {
        listeners.delete(listener);
        if (listeners.size === 0) {
            leave();
        }
    };
}

/** ตัดการเชื่อมต่อทั้งหมด (logout / เปลี่ยนร้าน) */
export function disconnectRealtime(): void {
    listeners.clear();
    leave();
    echo?.disconnect();
    echo = null;
}

/**
 * listener ที่รวบสัญญาณติด ๆ กันเป็นการเรียก onChange ครั้งเดียว — หนึ่งคำสั่ง (เช่นส่งครัว) ส่งหลายสัญญาณ
 * isRelevant กรองเฉพาะสัญญาณที่หน้านั้นสนใจ; สัญญาณ resync (null) ผ่านเสมอ
 */
export function coalescedListener(
    onChange: () => void,
    isRelevant: (activity: OrderActivity) => boolean = () => true,
): { listener: OrderActivityListener; cancel: () => void } {
    let pending: ReturnType<typeof setTimeout> | null = null;

    return {
        listener: (activity) => {
            if (pending !== null || (activity !== null && !isRelevant(activity))) {
                return;
            }
            pending = setTimeout(() => {
                pending = null;
                onChange();
            }, COALESCE_MS);
        },
        cancel: () => {
            if (pending !== null) {
                clearTimeout(pending);
                pending = null;
            }
        },
    };
}

/** ผูกกับ lifecycle ของ component — subscribe ตอน mount, เลิกตอน unmount */
export function useStoreActivity(
    storeId: () => string | null,
    onChange: () => void,
    isRelevant?: (activity: OrderActivity) => boolean,
): void {
    const { listener, cancel } = coalescedListener(onChange, isRelevant);
    let stop: (() => void) | null = null;
    onMounted(() => {
        const id = storeId();
        if (id) {
            stop = onStoreActivity(id, listener);
        }
    });
    onBeforeUnmount(() => {
        stop?.();
        cancel();
    });
}

/** ควร poll รอบนี้ไหม — ขณะ live ข้ามจนกว่าจะครบ LIVE_FALLBACK_POLL_MS นับจากรอบล่าสุด */
export function shouldPoll(lastPolledAt: number): boolean {
    return realtimeState.value !== 'live' || Date.now() - lastPolledAt >= LIVE_FALLBACK_POLL_MS;
}
