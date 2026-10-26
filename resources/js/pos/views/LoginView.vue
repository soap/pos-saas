<script setup lang="ts">
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ApiError } from '@pos/api/client';
import { pinnedSlug } from '@pos/lib/config';
import { errorMessage } from '@pos/lib/toast';
import { useAuthStore } from '@pos/stores/auth';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

const email = ref('');
const password = ref('');
const isSubmitting = ref(false);
const error = ref<ApiError | null>(null);

async function submit(): Promise<void> {
    isSubmitting.value = true;
    error.value = null;
    try {
        await auth.login(email.value, password.value);
        const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : null;
        await router.replace(redirect ?? { name: 'stores' });
    } catch (e) {
        error.value = e instanceof ApiError ? e : new ApiError(0, 'unknown', errorMessage(e));
        password.value = '';
    } finally {
        isSubmitting.value = false;
    }
}
</script>

<template>
    <main class="flex min-h-full items-center justify-center p-4">
        <form class="w-full max-w-sm space-y-5 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 sm:p-8" @submit.prevent="submit">
            <div>
                <div class="mb-4 flex size-12 items-center justify-center rounded-xl bg-teal-700 text-xl font-bold text-white">P</div>
                <h1 class="text-2xl font-semibold">เข้าสู่ระบบ POS</h1>
                <p v-if="pinnedSlug" class="mt-1 inline-block rounded-lg bg-teal-50 px-2 py-0.5 text-sm font-medium text-teal-800">ร้าน {{ pinnedSlug }}</p>
                <p class="mt-1 text-sm text-slate-500">ใช้อีเมลและรหัสผ่านของพนักงาน</p>
            </div>

            <p v-if="error && error.code !== 'validation_failed'" class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700" role="alert">
                {{ error.message }}
            </p>

            <div>
                <label for="email" class="field-label">อีเมล</label>
                <input id="email" v-model="email" type="email" autocomplete="username" required class="input" />
                <p v-if="error?.fieldError('email')" class="mt-1 text-sm text-rose-600">{{ error.fieldError('email') }}</p>
            </div>

            <div>
                <label for="password" class="field-label">รหัสผ่าน</label>
                <input id="password" v-model="password" type="password" autocomplete="current-password" required class="input" />
                <p v-if="error?.fieldError('password')" class="mt-1 text-sm text-rose-600">{{ error.fieldError('password') }}</p>
            </div>

            <button type="submit" class="btn btn-primary w-full" :disabled="isSubmitting">
                {{ isSubmitting ? 'กำลังเข้าสู่ระบบ…' : 'เข้าสู่ระบบ' }}
            </button>
        </form>
    </main>
</template>
