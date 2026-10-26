<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { api } from '@pos/api/client';
import type { Category } from '@pos/api/types';
import BaseModal from '@pos/components/BaseModal.vue';
import { toast, toastError } from '@pos/lib/toast';

const categories = ref<Category[]>([]);
const isLoading = ref(true);
/** null = ปิด, 'new' = สร้าง, Category = แก้ไข */
const editing = ref<Category | 'new' | null>(null);
const form = ref({ name: '', sort_order: 0, is_active: true });
const isSaving = ref(false);

async function load(): Promise<void> {
    try {
        categories.value = (await api.get<{ data: Category[] }>('/categories')).data;
    } catch (e) {
        toastError(e);
    } finally {
        isLoading.value = false;
    }
}

function open(category: Category | 'new'): void {
    editing.value = category;
    form.value =
        category === 'new'
            ? { name: '', sort_order: (categories.value.at(-1)?.sort_order ?? 0) + 10, is_active: true }
            : { name: category.name, sort_order: category.sort_order, is_active: category.is_active };
}

async function save(): Promise<void> {
    isSaving.value = true;
    try {
        if (editing.value === 'new') {
            await api.post('/categories', form.value);
        } else if (editing.value) {
            await api.patch(`/categories/${editing.value.id}`, form.value);
        }
        toast('บันทึกหมวดหมู่แล้ว', 'success');
        editing.value = null;
        await load();
    } catch (e) {
        toastError(e);
    } finally {
        isSaving.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-4">
        <header class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">หมวดหมู่</h1>
                <p class="text-sm text-slate-500">ใช้ร่วมกันทุกสาขา · เรียงตามลำดับจากน้อยไปมาก</p>
            </div>
            <button type="button" class="btn btn-primary" @click="open('new')">+ เพิ่มหมวดหมู่</button>
        </header>

        <p v-if="isLoading" class="text-slate-500">กำลังโหลด…</p>
        <p v-else-if="categories.length === 0" class="rounded-2xl bg-white p-8 text-center text-slate-500 ring-1 ring-slate-200">ยังไม่มีหมวดหมู่</p>
        <ul v-else class="divide-y divide-slate-100 overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200">
            <li v-for="category in categories" :key="category.id">
                <button type="button" class="flex w-full items-center gap-4 px-4 py-3 text-left hover:bg-slate-50" @click="open(category)">
                    <span class="w-10 text-right text-sm text-slate-400 tabular-nums">{{ category.sort_order }}</span>
                    <span class="flex-1 font-medium" :class="{ 'text-slate-400 line-through': !category.is_active }">{{ category.name }}</span>
                    <span v-if="!category.is_active" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600">ซ่อนจากเมนู</span>
                </button>
            </li>
        </ul>

        <BaseModal v-if="editing" :title="editing === 'new' ? 'เพิ่มหมวดหมู่' : 'แก้ไขหมวดหมู่'" @close="editing = null">
            <form id="category-form" class="space-y-4" @submit.prevent="save">
                <div>
                    <label for="category-name" class="field-label">ชื่อ</label>
                    <input id="category-name" v-model="form.name" type="text" required maxlength="255" class="input" />
                </div>
                <div>
                    <label for="category-sort" class="field-label">ลำดับ</label>
                    <input id="category-sort" v-model.number="form.sort_order" type="number" step="1" class="input" />
                </div>
                <label class="flex items-center gap-3">
                    <input v-model="form.is_active" type="checkbox" class="size-5 accent-teal-700" />
                    <span>แสดงในเมนู</span>
                </label>
            </form>
            <template #footer>
                <button type="button" class="btn btn-secondary flex-1" @click="editing = null">ยกเลิก</button>
                <button type="submit" form="category-form" class="btn btn-primary flex-2" :disabled="isSaving">บันทึก</button>
            </template>
        </BaseModal>
    </div>
</template>
