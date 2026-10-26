import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { ApiError, api, setRequestContext } from '@pos/api/client';
import type { CashierShift, Context, StoreDetail } from '@pos/api/types';
import { scopedKey } from '@pos/lib/config';
import { storage } from '@pos/lib/storage';

const TENANT_KEY = scopedKey('pos.tenant_id');
const STORE_KEY = scopedKey('pos.store_id');

/**
 * tenant/store ที่เลือกทำงานอยู่ — การเลือกเป็นแค่ "context" สิทธิ์จริงมาจาก server (GET /context)
 */
export const useSessionStore = defineStore('session', () => {
    const tenantId = ref<string | null>(storage.get(TENANT_KEY));
    const storeId = ref<string | null>(storage.get(STORE_KEY));
    const context = ref<Context | null>(null);
    const store = ref<StoreDetail | null>(null);
    const shift = ref<CashierShift | null>(null);
    /** server time − client time (ms) เพื่อแสดงเวลาโดยไม่เชื่อนาฬิกาเครื่อง */
    const serverOffsetMs = ref(0);

    setRequestContext({ tenantId: tenantId.value, storeId: storeId.value });

    const hasStore = computed(() => tenantId.value !== null && storeId.value !== null);
    const isReady = computed(() => context.value?.store != null);
    /** สาขาที่ไม่ได้ตั้งค่าไว้ถือว่าใช้ครัว (ตรงกับ default ฝั่ง server) */
    const kitchenEnabled = computed(() => store.value?.settings?.kitchen_enabled ?? true);

    function can(permission: string): boolean {
        return context.value?.permissions.includes(permission) ?? false;
    }

    function select(nextTenantId: string, nextStoreId: string): void {
        tenantId.value = nextTenantId;
        storeId.value = nextStoreId;
        storage.set(TENANT_KEY, nextTenantId);
        storage.set(STORE_KEY, nextStoreId);
        setRequestContext({ tenantId: nextTenantId, storeId: nextStoreId });
        context.value = null;
        store.value = null;
        shift.value = null;
    }

    function clear(): void {
        tenantId.value = null;
        storeId.value = null;
        storage.set(TENANT_KEY, null);
        storage.set(STORE_KEY, null);
        setRequestContext({ tenantId: null, storeId: null });
        context.value = null;
        store.value = null;
        shift.value = null;
    }

    /** โหลด context ของสาขา; ถ้าไม่มีสิทธิ์แล้ว (เช่นถูกถอด membership) ล้างการเลือกทิ้ง */
    async function load(): Promise<void> {
        try {
            const requestedAt = Date.now();
            context.value = await api.get<Context>('/context');
            serverOffsetMs.value = new Date(context.value.server_time).getTime() - (requestedAt + Date.now()) / 2;
        } catch (error) {
            if (error instanceof ApiError && error.status === 403) {
                clear();
            }
            throw error;
        }

        await Promise.all([loadStoreDetail(), refreshShift()]);
    }

    async function loadStoreDetail(): Promise<void> {
        if (!can('stores.view')) {
            return;
        }
        const response = await api.get<{ data: StoreDetail[] }>('/stores');
        store.value = response.data.find((s) => s.id === storeId.value) ?? null;
    }

    async function refreshShift(): Promise<void> {
        if (!can('cashier.operate')) {
            shift.value = null;

            return;
        }
        const response = await api.get<{ data: CashierShift | null }>('/cashier-shifts/current');
        shift.value = response.data;
    }

    function serverNow(): Date {
        return new Date(Date.now() + serverOffsetMs.value);
    }

    return {
        tenantId,
        storeId,
        context,
        store,
        shift,
        hasStore,
        isReady,
        kitchenEnabled,
        can,
        select,
        clear,
        load,
        refreshShift,
        serverNow,
    };
});
