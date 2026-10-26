import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { api } from '@pos/api/client';
import type { KitchenTicket, KitchenTicketPage, KitchenTicketStatus } from '@pos/api/types';
import { scopedKey } from '@pos/lib/config';
import { storage } from '@pos/lib/storage';

type ConnectionState = 'connecting' | 'live' | 'offline';

/** ทำ full refresh เป็นระยะ กันกรณี delta หลุดบางรายการ (ภาคผนวก D3 Fallback) */
const FULL_SYNC_EVERY_MS = 5 * 60 * 1000;
const MAX_BACKOFF_MS = 30_000;
const ACTIVE: KitchenTicketStatus[] = ['queued', 'preparing', 'ready'];
/** station ที่จอเครื่องนี้แสดง — จำไว้ต่อเครื่อง (แท็บเล็ตแต่ละจุดในครัว) */
const STATION_KEY = scopedKey('pos.kitchen.station');

/**
 * คิวงานครัวแบบ polling: full sync ครั้งแรก แล้วดึงเฉพาะที่เปลี่ยนด้วย updated_after = server_time รอบก่อน
 * ไม่ใช้นาฬิกา browser เป็น cursor
 */
export const useKitchenStore = defineStore('kitchen', () => {
    const tickets = ref<Map<string, KitchenTicket>>(new Map());
    const connection = ref<ConnectionState>('connecting');
    /** เวลาเครื่อง (ms) ที่ sync สำเร็จล่าสุด — ใช้แสดง "ข้อมูลล่าสุดเมื่อ" และตัดสินว่า stale */
    const lastSyncAt = ref<number | null>(null);
    const pollIntervalSeconds = ref(3);
    const lateThresholdMinutes = ref(15);
    /** id ของ ticket ใหม่ที่เพิ่งเข้ามา (ใช้ไฮไลต์การ์ด) */
    const freshIds = ref<Set<string>>(new Set());
    /** '' = ทุก station, 'main' = ครัวหลัก, หรือ id ของ station */
    const station = ref<string>(storage.get(STATION_KEY) ?? '');

    let cursor: string | null = null;
    let lastFullSyncAt = 0;
    let timer: ReturnType<typeof setTimeout> | null = null;
    let failures = 0;
    let running = false;
    let onNewTickets: (count: number) => void = () => {};
    /** เพิ่มทุกครั้งที่เปลี่ยน station — ผล poll ที่ค้างจาก station เดิมจะถูกทิ้ง */
    let generation = 0;

    const byStatus = computed(() => {
        const groups: Record<'queued' | 'preparing' | 'ready', KitchenTicket[]> = { queued: [], preparing: [], ready: [] };
        for (const ticket of tickets.value.values()) {
            if (ticket.status === 'queued' || ticket.status === 'preparing' || ticket.status === 'ready') {
                groups[ticket.status].push(ticket);
            }
        }
        const bySentAt = (a: KitchenTicket, b: KitchenTicket) => (a.sent_at ?? '').localeCompare(b.sent_at ?? '');
        groups.queued.sort(bySentAt);
        groups.preparing.sort(bySentAt);
        groups.ready.sort(bySentAt);

        return groups;
    });

    /** ดึงทุกหน้า (keyset) ของ query เดียวกัน; server_time ของหน้าแรกคือ cursor รอบถัดไป */
    async function fetchAll(updatedAfter: string | null): Promise<{ tickets: KitchenTicket[]; serverTime: string }> {
        const collected: KitchenTicket[] = [];
        let nextCursor: string | null = null;
        let serverTime: string | null = null;
        do {
            const page: KitchenTicketPage = await api.get<KitchenTicketPage>('/kitchen/tickets', {
                updated_after: updatedAfter ?? undefined,
                cursor: nextCursor ?? undefined,
                station: station.value || undefined,
            });
            serverTime ??= page.server_time;
            pollIntervalSeconds.value = page.poll_interval_seconds;
            lateThresholdMinutes.value = page.late_threshold_minutes;
            collected.push(...page.data);
            nextCursor = page.has_more ? page.next_cursor : null;
        } while (nextCursor !== null);

        return { tickets: collected, serverTime: serverTime! };
    }

    function markFresh(ids: string[]): void {
        if (ids.length === 0) {
            return;
        }
        freshIds.value = new Set([...freshIds.value, ...ids]);
        setTimeout(() => {
            const next = new Set(freshIds.value);
            ids.forEach((id) => next.delete(id));
            freshIds.value = next;
        }, 15_000);
    }

    async function sync(): Promise<void> {
        const isFull = cursor === null || Date.now() - lastFullSyncAt > FULL_SYNC_EVERY_MS;
        const isFirstLoad = lastSyncAt.value === null;
        const startedAt = generation;
        const result = await fetchAll(isFull ? null : cursor);
        if (startedAt !== generation) {
            return;
        }

        const next = isFull ? new Map<string, KitchenTicket>() : new Map(tickets.value);
        const newQueued: string[] = [];
        for (const ticket of result.tickets) {
            if (!ACTIVE.includes(ticket.status)) {
                next.delete(ticket.id);
                continue;
            }
            if (ticket.status === 'queued' && !tickets.value.has(ticket.id)) {
                newQueued.push(ticket.id);
            }
            next.set(ticket.id, ticket);
        }
        tickets.value = next;
        cursor = result.serverTime;
        if (isFull) {
            lastFullSyncAt = Date.now();
        }
        lastSyncAt.value = Date.now();

        if (!isFirstLoad && newQueued.length > 0) {
            markFresh(newQueued);
            onNewTickets(newQueued.length);
        }
    }

    async function tick(): Promise<void> {
        if (!running) {
            return;
        }
        let delay = pollIntervalSeconds.value * 1000;
        try {
            await sync();
            failures = 0;
            connection.value = 'live';
        } catch {
            failures++;
            connection.value = 'offline';
            delay = Math.min(MAX_BACKOFF_MS, delay * 2 ** failures);
        }
        if (running) {
            timer = setTimeout(tick, delay);
        }
    }

    function start(newTicketHandler: (count: number) => void): void {
        onNewTickets = newTicketHandler;
        if (running) {
            return;
        }
        running = true;
        connection.value = 'connecting';
        void tick();
    }

    function stop(): void {
        running = false;
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    }

    /** ดึงทันที (เช่นกลับมาออนไลน์ หรือแท็บกลับมา active) */
    function syncNow(): void {
        if (!running) {
            return;
        }
        if (timer !== null) {
            clearTimeout(timer);
        }
        void tick();
    }

    /** เปลี่ยน station → ล้างคิวแล้ว full sync ใหม่ (ไม่ส่งเสียงสำหรับงานที่ค้างอยู่เดิม) */
    function setStation(next: string): void {
        generation++;
        station.value = next;
        storage.set(STATION_KEY, next || null);
        tickets.value = new Map();
        cursor = null;
        lastSyncAt.value = null;
        syncNow();
    }

    function apply(ticket: KitchenTicket): void {
        const next = new Map(tickets.value);
        if (ACTIVE.includes(ticket.status)) {
            next.set(ticket.id, ticket);
        } else {
            next.delete(ticket.id);
        }
        tickets.value = next;
    }

    async function transition(id: string, action: 'acknowledge' | 'ready' | 'served'): Promise<void> {
        apply((await api.post<{ data: KitchenTicket }>(`/kitchen/tickets/${id}/${action}`)).data);
    }

    async function cancel(id: string, reason: string): Promise<void> {
        apply((await api.post<{ data: KitchenTicket }>(`/kitchen/tickets/${id}/cancel`, { reason })).data);
    }

    function reset(): void {
        stop();
        tickets.value = new Map();
        cursor = null;
        lastFullSyncAt = 0;
        lastSyncAt.value = null;
        failures = 0;
        connection.value = 'connecting';
    }

    return {
        tickets,
        byStatus,
        connection,
        lastSyncAt,
        pollIntervalSeconds,
        lateThresholdMinutes,
        freshIds,
        station,
        setStation,
        start,
        stop,
        syncNow,
        transition,
        cancel,
        reset,
    };
});
