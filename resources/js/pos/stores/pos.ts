import { defineStore } from 'pinia';
import { computed, ref, watch } from 'vue';
import { api, newIdempotencyKey } from '@pos/api/client';
import type { DiningTable, Menu, MenuProduct, ModifierOption, Order, OrderItemInput, OrderType } from '@pos/api/types';
import { useSessionStore } from '@pos/stores/session';

export interface DraftLine {
    key: string;
    product: MenuProduct;
    options: ModifierOption[];
    quantity: number;
    notes: string | null;
}

const OPEN_STATUSES = 'draft,placed,preparing,ready,served';

/**
 * ตะกร้า (รายการที่ยังไม่ส่ง server) + ออเดอร์ที่เปิดอยู่
 * ราคาในตะกร้าเป็นเพียงประมาณการ — ยอดจริงมาจาก server หลังสร้าง/เพิ่มรายการ
 */
export const usePosStore = defineStore('pos', () => {
    const menu = ref<Menu | null>(null);
    const lines = ref<DraftLine[]>([]);
    const order = ref<Order | null>(null);
    const orderType = ref<OrderType>('dine_in');
    const tableLabel = ref('');
    const tables = ref<DiningTable[]>([]);
    /** โต๊ะที่เลือกไว้สำหรับออเดอร์ใหม่ (ยังไม่สร้างออเดอร์) */
    const selectedTableId = ref<string | null>(null);
    const openOrders = ref<Order[]>([]);
    const isSubmitting = ref(false);

    /** key ของการส่งตะกร้าชุดนี้ — ใช้ซ้ำได้เมื่อกดใหม่หลังเน็ตหลุด แต่ต้องเปลี่ยนเมื่อตะกร้าเปลี่ยน */
    let submitKey: string | null = null;
    watch(lines, () => (submitKey = null), { deep: true });

    const draftTotalSatang = computed(() => lines.value.reduce((sum, line) => sum + lineTotal(line), 0));
    const activeItems = computed(() => order.value?.items?.filter((item) => item.status === 'active') ?? []);
    const unsentItemIds = computed(() => {
        const sent = new Set(order.value?.kitchen_tickets?.flatMap((t) => t.items?.map((i) => i.order_item_id) ?? []) ?? []);

        return new Set(activeItems.value.filter((item) => !sent.has(item.id)).map((item) => item.id));
    });
    const selectedTable = computed(() => tables.value.find((t) => t.id === selectedTableId.value) ?? null);
    const isOrderOpen = computed(() => order.value !== null && !['completed', 'cancelled'].includes(order.value.status));

    function lineTotal(line: DraftLine): number {
        const deltas = line.options.reduce((sum, option) => sum + option.price_delta_satang, 0);

        return (line.product.price_satang + deltas) * line.quantity;
    }

    async function loadMenu(): Promise<void> {
        menu.value = (await api.get<{ data: Menu }>('/menu')).data;
    }

    function addLine(product: MenuProduct, options: ModifierOption[], notes: string | null, quantity = 1): void {
        const optionIds = options.map((o) => o.id).sort().join(',');
        const same = lines.value.find(
            (line) => line.product.id === product.id && line.notes === notes && line.options.map((o) => o.id).sort().join(',') === optionIds,
        );
        if (same) {
            same.quantity = Math.min(999, same.quantity + quantity);

            return;
        }
        lines.value.push({ key: crypto.randomUUID(), product, options, quantity, notes });
    }

    function changeLineQuantity(key: string, delta: number): void {
        const line = lines.value.find((l) => l.key === key);
        if (!line) {
            return;
        }
        line.quantity += delta;
        if (line.quantity <= 0) {
            lines.value = lines.value.filter((l) => l.key !== key);
        }
    }

    function removeLine(key: string): void {
        lines.value = lines.value.filter((l) => l.key !== key);
    }

    function toInput(line: DraftLine): OrderItemInput {
        return { product_id: line.product.id, quantity: line.quantity, modifier_option_ids: line.options.map((o) => o.id), notes: line.notes };
    }

    /** ส่งตะกร้า: สร้างออเดอร์ใหม่หรือเพิ่มเข้าออเดอร์เดิม แล้วส่งครัวถ้าสาขาใช้ครัว */
    async function submitDraft(): Promise<void> {
        if (lines.value.length === 0 || isSubmitting.value) {
            return;
        }
        isSubmitting.value = true;
        submitKey ??= newIdempotencyKey();
        try {
            const items = lines.value.map(toInput);
            const response = order.value
                ? await api.postIdempotent<{ data: Order }>(`/orders/${order.value.id}/items`, { items }, submitKey)
                : await api.postIdempotent<{ data: Order }>(
                      '/orders',
                      {
                          order_type: orderType.value,
                          dining_table_id: orderType.value === 'dine_in' ? selectedTableId.value : null,
                          table_label: tableLabel.value.trim() || null,
                          items,
                      },
                      submitKey,
                  );
            order.value = response.data;
            lines.value = [];

            if (useSessionStore().kitchenEnabled && unsentItemIds.value.size > 0) {
                await sendToKitchen();
            }
        } finally {
            isSubmitting.value = false;
        }
    }

    async function sendToKitchen(): Promise<void> {
        if (!order.value) {
            return;
        }
        await api.postIdempotent(`/orders/${order.value.id}/send-to-kitchen`, {}, newIdempotencyKey());
        await refreshOrder();
    }

    async function refreshOrder(): Promise<void> {
        if (order.value) {
            order.value = (await api.get<{ data: Order }>(`/orders/${order.value.id}`)).data;
        }
    }

    async function openOrder(id: string): Promise<void> {
        order.value = (await api.get<{ data: Order }>(`/orders/${id}`)).data;
        orderType.value = order.value.order_type;
        tableLabel.value = order.value.table_label ?? '';
    }

    async function loadOpenOrders(): Promise<void> {
        openOrders.value = (await api.get<{ data: Order[] }>('/orders', { status: OPEN_STATUSES })).data;
    }

    async function loadTables(): Promise<void> {
        tables.value = (await api.get<{ data: DiningTable[] }>('/tables')).data;
    }

    /** เริ่มออเดอร์ใหม่บนโต๊ะว่าง */
    function startOnTable(table: DiningTable): void {
        startNewOrder();
        orderType.value = 'dine_in';
        selectedTableId.value = table.id;
    }

    async function moveTable(tableId: string | null): Promise<void> {
        order.value = (await api.post<{ data: Order }>(`/orders/${order.value!.id}/move-table`, { dining_table_id: tableId })).data;
        tableLabel.value = order.value.table_label ?? '';
        await loadTables();
    }

    async function updateItemQuantity(itemId: string, quantity: number): Promise<void> {
        order.value = (await api.patch<{ data: Order }>(`/orders/${order.value!.id}/items/${itemId}`, { quantity })).data;
    }

    async function removeItem(itemId: string): Promise<void> {
        order.value = (await api.delete<{ data: Order }>(`/orders/${order.value!.id}/items/${itemId}`)).data;
    }

    async function applyDiscount(discountSatang: number, reason: string | null): Promise<void> {
        order.value = (await api.post<{ data: Order }>(`/orders/${order.value!.id}/discount`, { discount_satang: discountSatang, reason })).data;
    }

    async function cancelOrder(reason: string): Promise<void> {
        order.value = (await api.post<{ data: Order }>(`/orders/${order.value!.id}/cancel`, { reason })).data;
    }

    /** เริ่มออเดอร์ใหม่ (ไม่ลบตะกร้าที่ค้างอยู่ — ผู้ใช้อาจตั้งใจย้ายไปออเดอร์ใหม่) */
    function startNewOrder(): void {
        order.value = null;
        selectedTableId.value = null;
        tableLabel.value = '';
        orderType.value = 'dine_in';
    }

    function reset(): void {
        menu.value = null;
        lines.value = [];
        openOrders.value = [];
        tables.value = [];
        startNewOrder();
    }

    return {
        menu,
        lines,
        order,
        orderType,
        tableLabel,
        openOrders,
        tables,
        selectedTableId,
        selectedTable,
        isSubmitting,
        draftTotalSatang,
        activeItems,
        unsentItemIds,
        isOrderOpen,
        lineTotal,
        loadMenu,
        addLine,
        changeLineQuantity,
        removeLine,
        submitDraft,
        sendToKitchen,
        refreshOrder,
        openOrder,
        loadOpenOrders,
        loadTables,
        startOnTable,
        moveTable,
        updateItemQuantity,
        removeItem,
        applyDiscount,
        cancelOrder,
        startNewOrder,
        reset,
    };
});
