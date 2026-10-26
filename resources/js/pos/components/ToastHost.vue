<script setup lang="ts">
import { dismissToast, toasts } from '@pos/lib/toast';

const styles = {
    success: 'bg-emerald-600 text-white',
    error: 'bg-rose-600 text-white',
    info: 'bg-slate-800 text-white',
};
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 bottom-4 z-50 flex flex-col items-center gap-2 px-4" aria-live="polite">
        <button
            v-for="item in toasts"
            :key="item.id"
            type="button"
            class="pointer-events-auto max-w-md rounded-xl px-4 py-3 text-left text-sm font-medium shadow-lg"
            :class="styles[item.kind]"
            @click="dismissToast(item.id)"
        >
            {{ item.message }}
        </button>
    </div>
</template>
