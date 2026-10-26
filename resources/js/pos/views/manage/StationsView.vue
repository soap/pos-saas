<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { ApiError, api } from '@pos/api/client';
import type { KitchenStation } from '@pos/api/types';
import BaseModal from '@pos/components/BaseModal.vue';
import { toast, toastError } from '@pos/lib/toast';

const stations = ref<KitchenStation[]>([]);
const isLoading = ref(true);
const isSaving = ref(false);
/** null = ปิด, 'new' = สร้าง, KitchenStation = แก้ไข */
const editing = ref<KitchenStation | 'new' | null>(null);
const form = ref({ name: '', sort_order: 0, is_active: true });
const formError = ref<ApiError | null>(null);

async function load(): Promise<void> {
    try {
        stations.value = (await api.get<{ data: KitchenStation[] }>('/kitchen-stations', { include_inactive: 1 })).data;
    } catch (e) {
        toastError(e);
    } finally {
        isLoading.value = false;
    }
}

function open(station: KitchenStation | 'new'): void {
    editing.value = station;
    formError.value = null;
    form.value =
        station === 'new'
            ? { name: '', sort_order: (stations.value.at(-1)?.sort_order ?? 0) + 10, is_active: true }
            : { name: station.name, sort_order: station.sort_order, is_active: station.is_active };
}

async function save(): Promise<void> {
    isSaving.value = true;
    formError.value = null;
    const payload = { ...form.value, name: form.value.name.trim() };
    try {
        if (editing.value === 'new') {
            await api.post('/kitchen-stations', payload);
        } else if (editing.value) {
            await api.patch(`/kitchen-stations/${editing.value.id}`, payload);
        }
        toast('บันทึกจุดในครัวแล้ว', 'success');
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

onMounted(load);
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">จุดในครัว</h1>
                <p class="text-sm text-slate-500">
                    แยกงานครัวตามจุดทำ (เช่น เครื่องดื่ม, ย่าง) · เลือกจุดของสินค้าได้ที่หน้า "สินค้า" · สินค้าที่ไม่ระบุไปที่ครัวหลัก
                </p>
            </div>
            <button type="button" class="btn btn-primary" @click="open('new')">+ เพิ่มจุด</button>
        </header>

        <p v-if="isLoading" class="text-slate-500">กำลังโหลด…</p>
        <ul v-else class="divide-y divide-slate-100 overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200">
            <li class="flex items-center gap-4 px-4 py-3 text-slate-500">
                <span class="w-10" />
                <span class="flex-1">ครัวหลัก <span class="text-xs">(ค่าเริ่มต้น — แก้ไขไม่ได้)</span></span>
            </li>
            <li v-for="station in stations" :key="station.id">
                <button type="button" class="flex w-full items-center gap-4 px-4 py-3 text-left hover:bg-slate-50" @click="open(station)">
                    <span class="w-10 text-right text-sm text-slate-400 tabular-nums">{{ station.sort_order }}</span>
                    <span class="flex-1 font-medium" :class="{ 'text-slate-400 line-through': !station.is_active }">{{ station.name }}</span>
                    <span v-if="!station.is_active" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600">ปิด — งานไปครัวหลัก</span>
                </button>
            </li>
        </ul>

        <BaseModal v-if="editing" :title="editing === 'new' ? 'เพิ่มจุดในครัว' : `แก้ไข ${editing.name}`" @close="editing = null">
            <form id="station-form" class="space-y-4" @submit.prevent="save">
                <div>
                    <label for="station-name" class="field-label">ชื่อจุด</label>
                    <input id="station-name" v-model="form.name" type="text" required maxlength="64" class="input" placeholder="เช่น เครื่องดื่ม" />
                    <p v-if="formError?.fieldError('name')" class="mt-1 text-sm text-rose-600">{{ formError.fieldError('name') }}</p>
                </div>
                <div>
                    <label for="station-sort" class="field-label">ลำดับ</label>
                    <input id="station-sort" v-model.number="form.sort_order" type="number" step="1" class="input" />
                </div>
                <label class="flex items-center gap-3">
                    <input v-model="form.is_active" type="checkbox" class="size-5 accent-teal-700" />
                    <span>เปิดใช้งาน (ปิดแล้ว รายการที่ส่งครัวครั้งถัดไปจะไปครัวหลัก)</span>
                </label>
            </form>
            <template #footer>
                <button type="button" class="btn btn-secondary flex-1" @click="editing = null">ยกเลิก</button>
                <button type="submit" form="station-form" class="btn btn-primary flex-2" :disabled="isSaving">บันทึก</button>
            </template>
        </BaseModal>
    </div>
</template>
