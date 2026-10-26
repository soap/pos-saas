<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ApiError, api } from '@pos/api/client';
import type { DiningTable } from '@pos/api/types';
import BaseModal from '@pos/components/BaseModal.vue';
import { toast, toastError } from '@pos/lib/toast';
import { useSessionStore } from '@pos/stores/session';

const session = useSessionStore();
const tables = ref<DiningTable[]>([]);
const isLoading = ref(true);
const isSaving = ref(false);

const editing = ref<DiningTable | 'new' | null>(null);
const form = ref({ name: '', zone: '', seats: '' as number | '', sort_order: 0, is_active: true });
const formError = ref<ApiError | null>(null);

const bulkOpen = ref(false);
const bulk = ref({ prefix: 'A', from: 1, to: 10, zone: '', seats: 4 as number | '' });

const zones = computed(() => [...new Set(tables.value.map((t) => t.zone).filter((z): z is string => !!z))]);
const grouped = computed(() => {
    const groups = new Map<string, DiningTable[]>();
    for (const table of tables.value) {
        const zone = table.zone || 'ทั่วไป';
        groups.set(zone, [...(groups.get(zone) ?? []), table]);
    }

    return [...groups.entries()];
});
const nextSortOrder = computed(() => Math.max(0, ...tables.value.map((t) => t.sort_order)) + 10);
const bulkNames = computed(() => {
    const { prefix, from, to } = bulk.value;
    if (!Number.isInteger(from) || !Number.isInteger(to) || from < 0 || to < from || to - from >= 100) {
        return [];
    }

    return Array.from({ length: to - from + 1 }, (_, i) => `${prefix.trim()}${from + i}`);
});

async function load(): Promise<void> {
    try {
        tables.value = (await api.get<{ data: DiningTable[] }>('/tables', { include_inactive: 1 })).data;
    } catch (e) {
        toastError(e);
    } finally {
        isLoading.value = false;
    }
}

function open(table: DiningTable | 'new'): void {
    editing.value = table;
    formError.value = null;
    form.value =
        table === 'new'
            ? { name: '', zone: zones.value[0] ?? '', seats: 4, sort_order: nextSortOrder.value, is_active: true }
            : { name: table.name, zone: table.zone ?? '', seats: table.seats ?? '', sort_order: table.sort_order, is_active: table.is_active };
}

async function save(): Promise<void> {
    isSaving.value = true;
    formError.value = null;
    const payload = {
        name: form.value.name.trim(),
        zone: form.value.zone.trim() || null,
        seats: form.value.seats === '' ? null : form.value.seats,
        sort_order: form.value.sort_order,
        is_active: form.value.is_active,
    };
    try {
        if (editing.value === 'new') {
            await api.post('/tables', payload);
        } else if (editing.value) {
            await api.patch(`/tables/${editing.value.id}`, payload);
        }
        toast('บันทึกโต๊ะแล้ว', 'success');
        editing.value = null;
        await load();
    } catch (e) {
        if (e instanceof ApiError && e.code === 'validation_failed') {
            formError.value = e;
        } else {
            toastError(e);
        }
    } finally {
        isSaving.value = false;
    }
}

/** สร้างทีละโต๊ะตามลำดับ — ชื่อที่ซ้ำกับของเดิมจะถูกข้ามและแจ้งจำนวน */
async function createBulk(): Promise<void> {
    isSaving.value = true;
    let created = 0;
    let skipped = 0;
    let sortOrder = nextSortOrder.value;
    for (const name of bulkNames.value) {
        try {
            await api.post('/tables', {
                name,
                zone: bulk.value.zone.trim() || null,
                seats: bulk.value.seats === '' ? null : bulk.value.seats,
                sort_order: sortOrder,
            });
            created++;
            sortOrder += 10;
        } catch (e) {
            if (e instanceof ApiError && e.code === 'validation_failed') {
                skipped++;
            } else {
                toastError(e);
                break;
            }
        }
    }
    toast(`สร้าง ${created} โต๊ะ${skipped ? ` (ข้ามชื่อซ้ำ ${skipped})` : ''}`, created ? 'success' : 'info');
    bulkOpen.value = false;
    isSaving.value = false;
    await load();
}

onMounted(load);
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">โต๊ะ</h1>
                <p class="text-sm text-slate-500">ผังโต๊ะของสาขา {{ session.context?.store?.name }} · โต๊ะที่ปิดใช้งานจะไม่แสดงในหน้าขาย</p>
            </div>
            <div class="flex gap-2">
                <button type="button" class="btn btn-secondary" @click="bulkOpen = true">เพิ่มหลายโต๊ะ</button>
                <button type="button" class="btn btn-primary" @click="open('new')">+ เพิ่มโต๊ะ</button>
            </div>
        </header>

        <p v-if="isLoading" class="text-slate-500">กำลังโหลด…</p>
        <p v-else-if="tables.length === 0" class="rounded-2xl bg-white p-8 text-center text-slate-500 ring-1 ring-slate-200">
            ยังไม่มีโต๊ะ — หน้าขายจะใช้การพิมพ์ชื่อโต๊ะ/ลูกค้าแทน
        </p>

        <section v-for="[zone, items] in grouped" :key="zone">
            <h2 class="mb-2 text-sm font-semibold tracking-wide text-slate-500">{{ zone }}</h2>
            <ul class="grid grid-cols-3 gap-2 sm:grid-cols-5">
                <li v-for="table in items" :key="table.id">
                    <button
                        type="button"
                        class="flex w-full flex-col items-start rounded-xl bg-white px-3 py-2 text-left ring-1 ring-slate-200 hover:ring-teal-600"
                        :class="{ 'opacity-50': !table.is_active }"
                        @click="open(table)"
                    >
                        <span class="font-semibold">{{ table.name }}</span>
                        <span class="text-xs text-slate-500">
                            {{ table.seats ? `${table.seats} ที่นั่ง` : '—' }}<template v-if="!table.is_active"> · ปิดใช้งาน</template>
                        </span>
                    </button>
                </li>
            </ul>
        </section>

        <datalist id="table-zones">
            <option v-for="zone in zones" :key="zone" :value="zone" />
        </datalist>

        <BaseModal v-if="editing" :title="editing === 'new' ? 'เพิ่มโต๊ะ' : `แก้ไขโต๊ะ ${editing.name}`" @close="editing = null">
            <form id="table-form" class="space-y-4" @submit.prevent="save">
                <div>
                    <label for="table-name" class="field-label">ชื่อโต๊ะ</label>
                    <input id="table-name" v-model="form.name" type="text" required maxlength="32" class="input" placeholder="เช่น A1" />
                    <p v-if="formError?.fieldError('name')" class="mt-1 text-sm text-rose-600">{{ formError.fieldError('name') }}</p>
                </div>
                <div>
                    <label for="table-zone" class="field-label">โซน</label>
                    <input id="table-zone" v-model="form.zone" type="text" maxlength="64" list="table-zones" class="input" placeholder="เช่น ในร้าน, ระเบียง" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="table-seats" class="field-label">ที่นั่ง</label>
                        <input id="table-seats" v-model.number="form.seats" type="number" min="1" max="999" class="input" />
                    </div>
                    <div>
                        <label for="table-sort" class="field-label">ลำดับ</label>
                        <input id="table-sort" v-model.number="form.sort_order" type="number" step="1" class="input" />
                    </div>
                </div>
                <label class="flex items-center gap-3">
                    <input v-model="form.is_active" type="checkbox" class="size-5 accent-teal-700" />
                    <span>เปิดใช้งาน</span>
                </label>
            </form>
            <template #footer>
                <button type="button" class="btn btn-secondary flex-1" @click="editing = null">ยกเลิก</button>
                <button type="submit" form="table-form" class="btn btn-primary flex-2" :disabled="isSaving">บันทึก</button>
            </template>
        </BaseModal>

        <BaseModal v-if="bulkOpen" title="เพิ่มหลายโต๊ะ" @close="bulkOpen = false">
            <form id="bulk-form" class="space-y-4" @submit.prevent="createBulk">
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label for="bulk-prefix" class="field-label">ตัวนำหน้า</label>
                        <input id="bulk-prefix" v-model="bulk.prefix" type="text" maxlength="20" class="input" />
                    </div>
                    <div>
                        <label for="bulk-from" class="field-label">เลขเริ่ม</label>
                        <input id="bulk-from" v-model.number="bulk.from" type="number" min="0" class="input" />
                    </div>
                    <div>
                        <label for="bulk-to" class="field-label">ถึงเลข</label>
                        <input id="bulk-to" v-model.number="bulk.to" type="number" :min="bulk.from" class="input" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="bulk-zone" class="field-label">โซน</label>
                        <input id="bulk-zone" v-model="bulk.zone" type="text" maxlength="64" list="table-zones" class="input" />
                    </div>
                    <div>
                        <label for="bulk-seats" class="field-label">ที่นั่ง</label>
                        <input id="bulk-seats" v-model.number="bulk.seats" type="number" min="1" max="999" class="input" />
                    </div>
                </div>
                <p class="text-sm text-slate-600">
                    <template v-if="bulkNames.length">จะสร้าง {{ bulkNames.length }} โต๊ะ: {{ bulkNames.slice(0, 6).join(', ') }}{{ bulkNames.length > 6 ? ' …' : '' }}</template>
                    <span v-else class="text-rose-600">ช่วงเลขไม่ถูกต้อง (สูงสุด 100 โต๊ะต่อครั้ง)</span>
                </p>
            </form>
            <template #footer>
                <button type="button" class="btn btn-secondary flex-1" @click="bulkOpen = false">ยกเลิก</button>
                <button type="submit" form="bulk-form" class="btn btn-primary flex-2" :disabled="isSaving || bulkNames.length === 0">
                    {{ isSaving ? 'กำลังสร้าง…' : 'สร้างโต๊ะ' }}
                </button>
            </template>
        </BaseModal>
    </div>
</template>
