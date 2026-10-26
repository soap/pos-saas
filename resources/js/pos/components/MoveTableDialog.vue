<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import BaseModal from '@pos/components/BaseModal.vue';
import { toast, toastError } from '@pos/lib/toast';
import { usePosStore } from '@pos/stores/pos';

const emit = defineEmits<{ close: [] }>();

const pos = usePosStore();
const isLoading = ref(true);
const movingTo = ref<string | null | undefined>(undefined);

const freeTables = computed(() => pos.tables.filter((t) => !t.open_order));

async function move(tableId: string | null): Promise<void> {
    movingTo.value = tableId;
    try {
        await pos.moveTable(tableId);
        toast(tableId ? `ย้ายไปโต๊ะ ${pos.order?.table_label} แล้ว` : 'เลิกผูกโต๊ะแล้ว', 'success');
        emit('close');
    } catch (e) {
        toastError(e);
        // โต๊ะปลายทางอาจถูกเครื่องอื่นเปิดไปแล้ว — ดึงผังใหม่
        await pos.loadTables().catch(() => {});
    } finally {
        movingTo.value = undefined;
    }
}

onMounted(async () => {
    try {
        await pos.loadTables();
    } catch (e) {
        toastError(e);
    } finally {
        isLoading.value = false;
    }
});
</script>

<template>
    <BaseModal :title="`ย้ายโต๊ะ — ออเดอร์ #${pos.order?.order_number ?? ''}`" size="lg" @close="emit('close')">
        <p class="mb-3 text-sm text-slate-600">
            ตอนนี้: <strong>{{ pos.order?.table_label ?? 'ไม่ได้ผูกโต๊ะ' }}</strong> · ใบที่ส่งครัวไปแล้วจะยังแสดงชื่อโต๊ะเดิม
        </p>
        <p v-if="isLoading" class="py-6 text-center text-slate-500">กำลังโหลด…</p>
        <p v-else-if="freeTables.length === 0" class="py-6 text-center text-slate-500">ไม่มีโต๊ะว่าง</p>
        <ul v-else class="grid grid-cols-3 gap-2 sm:grid-cols-4">
            <li v-for="table in freeTables" :key="table.id">
                <button
                    type="button"
                    class="flex min-h-16 w-full flex-col items-start justify-center rounded-xl bg-white px-3 py-2 text-left ring-1 ring-slate-300 hover:ring-2 hover:ring-teal-600 disabled:opacity-50"
                    :disabled="movingTo !== undefined"
                    @click="move(table.id)"
                >
                    <span class="font-semibold">{{ table.name }}</span>
                    <span v-if="table.zone" class="text-xs text-slate-500">{{ table.zone }}</span>
                </button>
            </li>
        </ul>
        <template v-if="pos.order?.dining_table_id" #footer>
            <button type="button" class="btn btn-secondary w-full" :disabled="movingTo !== undefined" @click="move(null)">เลิกผูกโต๊ะ (ไม่ระบุโต๊ะ)</button>
        </template>
    </BaseModal>
</template>
