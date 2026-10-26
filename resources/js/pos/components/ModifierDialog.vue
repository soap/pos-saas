<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import type { MenuProduct, ModifierGroup, ModifierOption } from '@pos/api/types';
import BaseModal from '@pos/components/BaseModal.vue';
import { formatBaht } from '@pos/lib/money';

const props = defineProps<{ product: MenuProduct }>();
const emit = defineEmits<{ close: []; add: [options: ModifierOption[], notes: string | null, quantity: number] }>();

/** group id → option ids ที่เลือก */
const selected = reactive<Record<string, string[]>>(Object.fromEntries(props.product.modifier_groups.map((g) => [g.id, []])));
const notes = ref('');
const quantity = ref(1);

function minRequired(group: ModifierGroup): number {
    return Math.max(group.min_select, group.is_required ? 1 : 0);
}

function toggle(group: ModifierGroup, option: ModifierOption): void {
    const current = selected[group.id] ?? [];
    if (current.includes(option.id)) {
        selected[group.id] = current.filter((id) => id !== option.id);
    } else if (group.max_select === 1) {
        selected[group.id] = [option.id];
    } else if (current.length < group.max_select) {
        selected[group.id] = [...current, option.id];
    }
}

const chosenOptions = computed(() =>
    props.product.modifier_groups.flatMap((g) => g.options.filter((o) => selected[g.id]?.includes(o.id))),
);
const unmetGroups = computed(() => props.product.modifier_groups.filter((g) => (selected[g.id]?.length ?? 0) < minRequired(g)));
const totalSatang = computed(
    () => (props.product.price_satang + chosenOptions.value.reduce((sum, o) => sum + o.price_delta_satang, 0)) * quantity.value,
);

function submit(): void {
    if (unmetGroups.value.length > 0) {
        return;
    }
    emit('add', chosenOptions.value, notes.value.trim() || null, quantity.value);
}
</script>

<template>
    <BaseModal :title="product.name" size="lg" @close="emit('close')">
        <div class="space-y-6">
            <fieldset v-for="group in product.modifier_groups" :key="group.id">
                <legend class="mb-2 flex w-full items-baseline justify-between gap-2">
                    <span class="font-semibold">{{ group.name }}</span>
                    <span class="text-xs" :class="unmetGroups.includes(group) ? 'font-medium text-rose-600' : 'text-slate-500'">
                        <template v-if="minRequired(group) > 0">ต้องเลือก {{ minRequired(group) }}{{ group.max_select > minRequired(group) ? `–${group.max_select}` : '' }}</template>
                        <template v-else>เลือกได้สูงสุด {{ group.max_select }}</template>
                    </span>
                </legend>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    <button
                        v-for="option in group.options"
                        :key="option.id"
                        type="button"
                        class="flex min-h-14 flex-col items-start justify-center rounded-xl px-3 py-2 text-left ring-1 transition"
                        :class="selected[group.id]?.includes(option.id) ? 'bg-teal-50 ring-2 ring-teal-600' : 'bg-white ring-slate-300 hover:bg-slate-50'"
                        :aria-pressed="selected[group.id]?.includes(option.id)"
                        @click="toggle(group, option)"
                    >
                        <span class="font-medium">{{ option.name }}</span>
                        <span v-if="option.price_delta_satang !== 0" class="text-sm text-slate-500 tabular-nums">
                            {{ option.price_delta_satang > 0 ? '+' : '' }}{{ formatBaht(option.price_delta_satang) }}
                        </span>
                    </button>
                </div>
            </fieldset>

            <div>
                <label for="item-notes" class="field-label">หมายเหตุ</label>
                <input id="item-notes" v-model="notes" type="text" maxlength="255" class="input" placeholder="เช่น ไม่ใส่ผัก, แยกน้ำ" />
            </div>

            <div class="flex items-center justify-between">
                <span class="font-medium">จำนวน</span>
                <div class="flex items-center gap-3">
                    <button type="button" class="btn btn-secondary w-12 px-0" aria-label="ลดจำนวน" :disabled="quantity <= 1" @click="quantity--">−</button>
                    <span class="w-8 text-center text-lg font-semibold tabular-nums">{{ quantity }}</span>
                    <button type="button" class="btn btn-secondary w-12 px-0" aria-label="เพิ่มจำนวน" :disabled="quantity >= 999" @click="quantity++">+</button>
                </div>
            </div>
        </div>

        <template #footer>
            <button type="button" class="btn btn-secondary flex-1" @click="emit('close')">ยกเลิก</button>
            <button type="button" class="btn btn-primary flex-2" :disabled="unmetGroups.length > 0" @click="submit">
                เพิ่มลงออเดอร์ · {{ formatBaht(totalSatang) }}
            </button>
        </template>
    </BaseModal>
</template>
