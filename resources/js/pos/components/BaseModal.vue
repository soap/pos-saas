<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue';

const props = withDefaults(defineProps<{ title: string; size?: 'md' | 'lg'; dismissible?: boolean }>(), {
    size: 'md',
    dismissible: true,
});
const emit = defineEmits<{ close: [] }>();

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && props.dismissible) {
        emit('close');
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-40 flex items-end justify-center bg-slate-900/50 sm:items-center sm:p-4" @click.self="dismissible && emit('close')">
            <div
                role="dialog"
                aria-modal="true"
                :aria-label="title"
                class="flex max-h-[92vh] w-full flex-col rounded-t-2xl bg-white shadow-xl sm:rounded-2xl"
                :class="size === 'lg' ? 'sm:max-w-2xl' : 'sm:max-w-md'"
            >
                <header class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                    <h2 class="text-lg font-semibold">{{ title }}</h2>
                    <button
                        v-if="dismissible"
                        type="button"
                        class="-mr-2 rounded-lg p-2 text-slate-500 hover:bg-slate-100"
                        aria-label="ปิด"
                        @click="emit('close')"
                    >
                        <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                        </svg>
                    </button>
                </header>
                <div class="overflow-y-auto px-5 py-4">
                    <slot />
                </div>
                <footer v-if="$slots.footer" class="flex gap-3 border-t border-slate-200 px-5 py-4">
                    <slot name="footer" />
                </footer>
            </div>
        </div>
    </Teleport>
</template>
