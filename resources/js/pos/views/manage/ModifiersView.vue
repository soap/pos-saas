<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { api } from '@pos/api/client';
import type { ManagedModifierGroup } from '@pos/api/types';
import BaseModal from '@pos/components/BaseModal.vue';
import { formatBaht } from '@pos/lib/money';
import { toast, toastError } from '@pos/lib/toast';

type Option = ManagedModifierGroup['options'][number];

const groups = ref<ManagedModifierGroup[]>([]);
const isLoading = ref(true);
const isSaving = ref(false);

const creating = ref(false);
const groupForm = ref({ name: '', min_select: 0, max_select: 1, is_required: false, options: [{ name: '', price: '0' }] });

const editingOption = ref<Option | null>(null);

const editingGroup = ref<ManagedModifierGroup | null>(null);
const editGroupForm = ref({ name: '', min_select: 0, max_select: 1, is_required: false });

/** กลุ่มที่กำลังเพิ่มตัวเลือกใหม่ */
const addingTo = ref<ManagedModifierGroup | null>(null);
const newOption = ref({ name: '', price: '0' });
const optionForm = ref({ name: '', price: '0', is_active: true });

/** ราคาตัวเลือกติดลบได้ (เช่น ไม่ใส่ไข่ −5) */
function toSatang(input: string): number | null {
    const match = input.trim().match(/^(-)?(\d{1,7})(?:\.(\d{1,2}))?$/);
    if (!match) {
        return null;
    }
    const value = Number(match[2]) * 100 + Number((match[3] ?? '').padEnd(2, '0'));

    return match[1] ? -value : value;
}

const groupFormValid = computed(
    () =>
        groupForm.value.name.trim() !== '' &&
        groupForm.value.max_select >= Math.max(1, groupForm.value.min_select) &&
        groupForm.value.options.length > 0 &&
        groupForm.value.options.every((o) => o.name.trim() !== '' && toSatang(o.price) !== null),
);

async function load(): Promise<void> {
    try {
        groups.value = (await api.get<{ data: ManagedModifierGroup[] }>('/modifier-groups')).data;
    } catch (e) {
        toastError(e);
    } finally {
        isLoading.value = false;
    }
}

function openCreate(): void {
    groupForm.value = { name: '', min_select: 0, max_select: 1, is_required: false, options: [{ name: '', price: '0' }] };
    creating.value = true;
}

async function createGroup(): Promise<void> {
    if (!groupFormValid.value) {
        return;
    }
    isSaving.value = true;
    try {
        await api.post('/modifier-groups', {
            name: groupForm.value.name.trim(),
            min_select: groupForm.value.min_select,
            max_select: groupForm.value.max_select,
            is_required: groupForm.value.is_required,
            sort_order: (groups.value.at(-1)?.sort_order ?? 0) + 10,
            options: groupForm.value.options.map((o) => ({ name: o.name.trim(), price_delta_satang: toSatang(o.price) })),
        });
        toast('สร้างกลุ่มตัวเลือกแล้ว', 'success');
        creating.value = false;
        await load();
    } catch (e) {
        toastError(e);
    } finally {
        isSaving.value = false;
    }
}

function openGroup(group: ManagedModifierGroup): void {
    editingGroup.value = group;
    editGroupForm.value = { name: group.name, min_select: group.min_select, max_select: group.max_select, is_required: group.is_required };
}

async function saveGroup(): Promise<void> {
    if (!editingGroup.value) {
        return;
    }
    isSaving.value = true;
    try {
        await api.patch(`/modifier-groups/${editingGroup.value.id}`, { ...editGroupForm.value, name: editGroupForm.value.name.trim() });
        toast('บันทึกกลุ่มแล้ว', 'success');
        editingGroup.value = null;
        await load();
    } catch (e) {
        toastError(e);
    } finally {
        isSaving.value = false;
    }
}

function openAddOption(group: ManagedModifierGroup): void {
    addingTo.value = group;
    newOption.value = { name: '', price: '0' };
}

async function addOption(): Promise<void> {
    const price = toSatang(newOption.value.price);
    if (!addingTo.value || price === null) {
        return;
    }
    isSaving.value = true;
    try {
        await api.post(`/modifier-groups/${addingTo.value.id}/options`, { name: newOption.value.name.trim(), price_delta_satang: price });
        toast('เพิ่มตัวเลือกแล้ว', 'success');
        addingTo.value = null;
        await load();
    } catch (e) {
        toastError(e);
    } finally {
        isSaving.value = false;
    }
}

function openOption(option: Option): void {
    editingOption.value = option;
    optionForm.value = { name: option.name, price: (option.price_delta_satang / 100).toFixed(2), is_active: option.is_active };
}

async function saveOption(): Promise<void> {
    const price = toSatang(optionForm.value.price);
    if (!editingOption.value || price === null) {
        return;
    }
    isSaving.value = true;
    try {
        await api.patch(`/modifier-options/${editingOption.value.id}`, {
            name: optionForm.value.name.trim(),
            price_delta_satang: price,
            is_active: optionForm.value.is_active,
        });
        toast('บันทึกตัวเลือกแล้ว', 'success');
        editingOption.value = null;
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
    <div class="mx-auto max-w-4xl space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">ตัวเลือกเสริม</h1>
                <p class="text-sm text-slate-500">เช่น ระดับความเผ็ด, เส้น, ท็อปปิ้ง · ผูกกับสินค้าได้ที่หน้า "สินค้า"</p>
            </div>
            <button type="button" class="btn btn-primary" @click="openCreate">+ สร้างกลุ่มตัวเลือก</button>
        </header>

        <p v-if="isLoading" class="text-slate-500">กำลังโหลด…</p>
        <p v-else-if="groups.length === 0" class="rounded-2xl bg-white p-8 text-center text-slate-500 ring-1 ring-slate-200">ยังไม่มีกลุ่มตัวเลือก</p>

        <section v-for="group in groups" :key="group.id" class="overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200">
            <header class="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-100 px-4 py-3">
                <h2 class="font-semibold">{{ group.name }}</h2>
                <div class="flex items-center gap-3">
                    <p class="text-sm text-slate-500">
                        {{ group.is_required ? 'บังคับเลือก' : 'ไม่บังคับ' }} · เลือก {{ group.min_select }}–{{ group.max_select }}
                    </p>
                    <button type="button" class="text-sm font-medium text-teal-700 hover:underline" @click="openGroup(group)">แก้ไขกลุ่ม</button>
                </div>
            </header>
            <ul class="divide-y divide-slate-100">
                <li v-for="option in group.options" :key="option.id">
                    <button type="button" class="flex w-full items-center justify-between gap-4 px-4 py-2.5 text-left hover:bg-slate-50" @click="openOption(option)">
                        <span :class="{ 'text-slate-400 line-through': !option.is_active }">{{ option.name }}</span>
                        <span class="text-sm tabular-nums" :class="option.price_delta_satang < 0 ? 'text-rose-600' : 'text-slate-600'">
                            {{ option.price_delta_satang > 0 ? '+' : '' }}{{ formatBaht(option.price_delta_satang) }}
                        </span>
                    </button>
                </li>
                <li>
                    <button type="button" class="w-full px-4 py-2.5 text-left text-sm font-medium text-teal-700 hover:bg-slate-50" @click="openAddOption(group)">
                        + เพิ่มตัวเลือก
                    </button>
                </li>
            </ul>
        </section>

        <BaseModal v-if="creating" title="สร้างกลุ่มตัวเลือก" size="lg" @close="creating = false">
            <form id="group-form" class="space-y-4" @submit.prevent="createGroup">
                <div>
                    <label for="group-name" class="field-label">ชื่อกลุ่ม</label>
                    <input id="group-name" v-model="groupForm.name" type="text" required maxlength="255" class="input" placeholder="เช่น ระดับความเผ็ด" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="group-min" class="field-label">เลือกอย่างน้อย</label>
                        <input id="group-min" v-model.number="groupForm.min_select" type="number" min="0" step="1" class="input" />
                    </div>
                    <div>
                        <label for="group-max" class="field-label">เลือกได้สูงสุด</label>
                        <input id="group-max" v-model.number="groupForm.max_select" type="number" min="1" step="1" class="input" />
                    </div>
                </div>
                <label class="flex items-center gap-3">
                    <input v-model="groupForm.is_required" type="checkbox" class="size-5 accent-teal-700" />
                    <span>บังคับเลือก</span>
                </label>
                <fieldset class="space-y-2">
                    <legend class="field-label">ตัวเลือก (ราคาเพิ่ม/ลด เป็นบาท)</legend>
                    <div v-for="(option, index) in groupForm.options" :key="index" class="flex gap-2">
                        <input v-model="option.name" type="text" required maxlength="255" class="input" :aria-label="`ชื่อตัวเลือกที่ ${index + 1}`" placeholder="ชื่อตัวเลือก" />
                        <input
                            v-model="option.price"
                            type="text"
                            inputmode="decimal"
                            class="input w-28 text-right tabular-nums"
                            :class="{ 'ring-rose-500': toSatang(option.price) === null }"
                            :aria-label="`ราคาตัวเลือกที่ ${index + 1}`"
                        />
                        <button
                            type="button"
                            class="btn btn-secondary w-12 shrink-0 px-0"
                            :disabled="groupForm.options.length === 1"
                            :aria-label="`ลบตัวเลือกที่ ${index + 1}`"
                            @click="groupForm.options.splice(index, 1)"
                        >
                            ×
                        </button>
                    </div>
                    <button type="button" class="btn btn-secondary w-full text-sm" @click="groupForm.options.push({ name: '', price: '0' })">+ เพิ่มตัวเลือก</button>
                </fieldset>
            </form>
            <template #footer>
                <button type="button" class="btn btn-secondary flex-1" @click="creating = false">ยกเลิก</button>
                <button type="submit" form="group-form" class="btn btn-primary flex-2" :disabled="isSaving || !groupFormValid">สร้างกลุ่ม</button>
            </template>
        </BaseModal>

        <BaseModal v-if="editingGroup" :title="`แก้ไขกลุ่ม ${editingGroup.name}`" @close="editingGroup = null">
            <form id="edit-group-form" class="space-y-4" @submit.prevent="saveGroup">
                <p class="text-sm text-slate-500">ออเดอร์เดิมไม่เปลี่ยน (เก็บชื่อกลุ่ม/ตัวเลือกไว้ตอนสั่งแล้ว)</p>
                <div>
                    <label for="edit-group-name" class="field-label">ชื่อกลุ่ม</label>
                    <input id="edit-group-name" v-model="editGroupForm.name" type="text" required maxlength="255" class="input" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="edit-group-min" class="field-label">เลือกอย่างน้อย</label>
                        <input id="edit-group-min" v-model.number="editGroupForm.min_select" type="number" min="0" step="1" class="input" />
                    </div>
                    <div>
                        <label for="edit-group-max" class="field-label">เลือกได้สูงสุด</label>
                        <input id="edit-group-max" v-model.number="editGroupForm.max_select" type="number" min="1" step="1" class="input" />
                    </div>
                </div>
                <label class="flex items-center gap-3">
                    <input v-model="editGroupForm.is_required" type="checkbox" class="size-5 accent-teal-700" />
                    <span>บังคับเลือก</span>
                </label>
            </form>
            <template #footer>
                <button type="button" class="btn btn-secondary flex-1" @click="editingGroup = null">ยกเลิก</button>
                <button
                    type="submit"
                    form="edit-group-form"
                    class="btn btn-primary flex-2"
                    :disabled="isSaving || editGroupForm.name.trim() === '' || editGroupForm.max_select < Math.max(1, editGroupForm.min_select)"
                >
                    บันทึก
                </button>
            </template>
        </BaseModal>

        <BaseModal v-if="addingTo" :title="`เพิ่มตัวเลือกใน ${addingTo.name}`" @close="addingTo = null">
            <form id="add-option-form" class="space-y-4" @submit.prevent="addOption">
                <div>
                    <label for="new-option-name" class="field-label">ชื่อตัวเลือก</label>
                    <input id="new-option-name" v-model="newOption.name" type="text" required maxlength="255" class="input" />
                </div>
                <div>
                    <label for="new-option-price" class="field-label">ราคาเพิ่ม/ลด (บาท)</label>
                    <input id="new-option-price" v-model="newOption.price" type="text" inputmode="decimal" class="input text-right tabular-nums" />
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-secondary flex-1" @click="addingTo = null">ยกเลิก</button>
                <button
                    type="submit"
                    form="add-option-form"
                    class="btn btn-primary flex-2"
                    :disabled="isSaving || newOption.name.trim() === '' || toSatang(newOption.price) === null"
                >
                    เพิ่ม
                </button>
            </template>
        </BaseModal>

        <BaseModal v-if="editingOption" title="แก้ไขตัวเลือก" @close="editingOption = null">
            <form id="option-form" class="space-y-4" @submit.prevent="saveOption">
                <div>
                    <label for="option-name" class="field-label">ชื่อ</label>
                    <input id="option-name" v-model="optionForm.name" type="text" required maxlength="255" class="input" />
                </div>
                <div>
                    <label for="option-price" class="field-label">ราคาเพิ่ม/ลด (บาท)</label>
                    <input id="option-price" v-model="optionForm.price" type="text" inputmode="decimal" class="input text-right tabular-nums" />
                </div>
                <label class="flex items-center gap-3">
                    <input v-model="optionForm.is_active" type="checkbox" class="size-5 accent-teal-700" />
                    <span>เปิดใช้งาน</span>
                </label>
            </form>
            <template #footer>
                <button type="button" class="btn btn-secondary flex-1" @click="editingOption = null">ยกเลิก</button>
                <button type="submit" form="option-form" class="btn btn-primary flex-2" :disabled="isSaving || toSatang(optionForm.price) === null">บันทึก</button>
            </template>
        </BaseModal>
    </div>
</template>
