<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import type { StoreSummary, TenantSummary } from '@pos/api/types';
import { pinnedSlug } from '@pos/lib/config';
import { toastError } from '@pos/lib/toast';
import { useAuthStore } from '@pos/stores/auth';
import { usePosStore } from '@pos/stores/pos';
import { useSessionStore } from '@pos/stores/session';

const auth = useAuthStore();
const session = useSessionStore();
const pos = usePosStore();
const router = useRouter();

const isLoading = ref(true);

/** URL ปักหมุดร้านไว้ → แสดงเฉพาะร้านนั้น (ร้านอื่นของผู้ใช้เปิดได้จากลิงก์ของร้านนั้นเอง) */
const tenantsWithStores = computed(() => auth.tenants.filter((t) => t.stores.length > 0 && (pinnedSlug === null || t.slug === pinnedSlug)));

async function choose(tenant: TenantSummary, store: StoreSummary): Promise<void> {
    session.select(tenant.id, store.id);
    pos.reset();
    await router.replace({ name: 'register' });
}

async function logout(): Promise<void> {
    await auth.logout();
    session.clear();
    pos.reset();
    await router.replace({ name: 'login' });
}

onMounted(async () => {
    try {
        await auth.fetchMe();
        const only = tenantsWithStores.value.length === 1 ? tenantsWithStores.value[0] : undefined;
        const activeStores = only?.stores.filter((s) => s.status === 'active') ?? [];
        if (only && activeStores.length === 1 && activeStores[0]) {
            await choose(only, activeStores[0]);
        }
    } catch (e) {
        toastError(e);
    } finally {
        isLoading.value = false;
    }
});
</script>

<template>
    <main class="mx-auto flex min-h-full max-w-2xl flex-col p-4 sm:p-8">
        <header class="mb-6 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">เลือกสาขา</h1>
                <p class="mt-1 text-sm text-slate-500">{{ auth.user?.name }} · {{ auth.user?.email }}</p>
            </div>
            <button type="button" class="btn btn-secondary" @click="logout">ออกจากระบบ</button>
        </header>

        <p v-if="isLoading" class="text-slate-500">กำลังโหลด…</p>

        <p v-else-if="tenantsWithStores.length === 0" class="rounded-2xl bg-white p-6 text-slate-600 ring-1 ring-slate-200">
            <template v-if="pinnedSlug">
                บัญชีนี้ไม่มีสิทธิ์ใช้งานร้าน <strong>{{ pinnedSlug }}</strong> — ตรวจสอบลิงก์ร้าน หรือให้เจ้าของร้านเพิ่มคุณเป็นพนักงาน
            </template>
            <template v-else>บัญชีนี้ยังไม่ได้รับสิทธิ์เข้าถึงสาขาใด กรุณาติดต่อผู้ดูแลร้าน</template>
        </p>

        <section v-for="tenant in tenantsWithStores" v-else :key="tenant.id" class="mb-6">
            <h2 class="mb-2 text-sm font-semibold tracking-wide text-slate-500 uppercase">{{ tenant.name }}</h2>
            <ul class="divide-y divide-slate-200 overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200">
                <li v-for="store in tenant.stores" :key="store.id">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-4 px-5 py-4 text-left hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="store.status !== 'active' || tenant.status !== 'active'"
                        @click="choose(tenant, store)"
                    >
                        <span>
                            <span class="block font-medium">{{ store.name }}</span>
                            <span class="block text-sm text-slate-500">รหัส {{ store.code }}</span>
                        </span>
                        <span v-if="store.status !== 'active'" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600">ปิดใช้งาน</span>
                        <span v-else class="text-teal-700" aria-hidden="true">›</span>
                    </button>
                </li>
            </ul>
        </section>
    </main>
</template>
