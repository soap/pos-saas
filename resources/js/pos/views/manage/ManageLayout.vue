<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ApiError } from '@pos/api/client';
import { toastError } from '@pos/lib/toast';
import { useAuthStore } from '@pos/stores/auth';
import { useSessionStore } from '@pos/stores/session';

const auth = useAuthStore();
const session = useSessionStore();
const router = useRouter();
const route = useRoute();

const isLoading = ref(!session.isReady);
const loadError = ref<string | null>(null);

/** เมนูหลังร้าน — แสดงเฉพาะส่วนที่ผู้ใช้มีสิทธิ์ (server ตรวจซ้ำทุก request) */
const NAV = [
    { name: 'manage.reports', label: 'รายงานยอดขาย', permission: 'reports.view' },
    { name: 'manage.products', label: 'สินค้า', permission: 'catalog.manage' },
    { name: 'manage.categories', label: 'หมวดหมู่', permission: 'catalog.manage' },
    { name: 'manage.modifiers', label: 'ตัวเลือกเสริม', permission: 'catalog.manage' },
    { name: 'manage.tables', label: 'โต๊ะ', permission: 'stores.manage' },
    { name: 'manage.stores', label: 'สาขา', permission: 'stores.view' },
    { name: 'manage.members', label: 'พนักงาน', permission: 'members.manage' },
    { name: 'manage.payment-accounts', label: 'บัญชีรับเงิน QR', permission: 'payments.configure' },
] as const;

const items = computed(() => NAV.filter((item) => session.can(item.permission)));
const current = computed(() => NAV.find((item) => item.name === route.name));

onMounted(async () => {
    if (!session.isReady) {
        try {
            await Promise.all([session.load(), auth.user ? null : auth.fetchMe()]);
        } catch (e) {
            if (e instanceof ApiError && e.status === 403) {
                toastError(e);
                await router.replace({ name: 'stores' });

                return;
            }
            loadError.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ';
        } finally {
            isLoading.value = false;
        }
    }
    // เข้า /manage ตรง ๆ หรือหน้าที่ไม่มีสิทธิ์ → ไปหน้าแรกที่มีสิทธิ์
    if (session.isReady && (!current.value || !session.can(current.value.permission))) {
        const first = items.value[0];
        await router.replace(first ? { name: first.name } : { name: 'register' });
    }
});
</script>

<template>
    <div class="flex min-h-dvh flex-col lg:flex-row">
        <aside class="bg-slate-900 text-slate-100 lg:sticky lg:top-0 lg:h-dvh lg:w-60 lg:shrink-0">
            <div class="flex items-center justify-between gap-2 px-4 py-3 lg:block">
                <div class="min-w-0">
                    <p class="truncate font-semibold">จัดการร้าน</p>
                    <p class="truncate text-xs text-slate-400">{{ session.context?.tenant.name }} · {{ session.context?.store?.name }}</p>
                </div>
                <RouterLink :to="{ name: 'register' }" class="shrink-0 rounded-lg px-3 py-2 text-sm hover:bg-slate-800 lg:mt-3 lg:block lg:px-0 lg:hover:bg-transparent lg:hover:underline">
                    ← กลับหน้าขาย
                </RouterLink>
            </div>
            <nav class="flex gap-1 overflow-x-auto px-2 pb-2 lg:flex-col lg:px-2" aria-label="เมนูจัดการร้าน">
                <RouterLink
                    v-for="item in items"
                    :key="item.name"
                    :to="{ name: item.name }"
                    class="shrink-0 rounded-lg px-3 py-2 text-sm whitespace-nowrap"
                    :class="route.name === item.name ? 'bg-teal-700 font-semibold text-white' : 'text-slate-300 hover:bg-slate-800'"
                >
                    {{ item.label }}
                </RouterLink>
            </nav>
        </aside>

        <main class="min-w-0 flex-1 p-4 sm:p-6">
            <p v-if="isLoading" class="text-slate-500">กำลังโหลด…</p>
            <div v-else-if="loadError" class="space-y-3">
                <p class="text-slate-700">{{ loadError }}</p>
                <button type="button" class="btn btn-primary" @click="router.go(0)">ลองอีกครั้ง</button>
            </div>
            <RouterView v-else-if="current && session.can(current.permission)" />
        </main>
    </div>
</template>
