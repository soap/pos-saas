<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { ApiError, api } from '@pos/api/client';
import type { ManagedStore, Member, RoleSlug } from '@pos/api/types';
import BaseModal from '@pos/components/BaseModal.vue';
import { toast, toastError } from '@pos/lib/toast';
import { useAuthStore } from '@pos/stores/auth';

const ROLE_LABELS: Record<RoleSlug, string> = { owner: 'เจ้าของร้าน', manager: 'ผู้จัดการ', cashier: 'แคชเชียร์', kitchen: 'ครัว' };

const auth = useAuthStore();
const members = ref<Member[]>([]);
const stores = ref<ManagedStore[]>([]);
const isLoading = ref(true);
const adding = ref(false);
const form = ref({ email: '', name: '', password: '', role: 'cashier' as RoleSlug, role_store_id: '', store_ids: [] as string[] });
const formError = ref<ApiError | null>(null);
const isSaving = ref(false);
const removing = ref<{ member: Member; role: string } | null>(null);

const storeName = computed(() => new Map(stores.value.map((s) => [s.id, s.name])));

// role ระดับสาขาต้องอยู่ในสาขาที่เข้าถึงได้ด้วย (server ตรวจซ้ำ)
watch(
    () => form.value.role_store_id,
    (storeId) => {
        if (storeId && !form.value.store_ids.includes(storeId)) {
            form.value.store_ids.push(storeId);
        }
    },
);

function roleLabel(slug: string): string {
    return ROLE_LABELS[slug as RoleSlug] ?? slug;
}

async function load(): Promise<void> {
    try {
        const [memberResponse, storeResponse] = await Promise.all([api.get<{ data: Member[] }>('/members'), api.get<{ data: ManagedStore[] }>('/stores')]);
        members.value = memberResponse.data;
        stores.value = storeResponse.data;
    } catch (e) {
        toastError(e);
    } finally {
        isLoading.value = false;
    }
}

function openAdd(): void {
    const firstStore = stores.value[0]?.id ?? '';
    form.value = { email: '', name: '', password: '', role: 'cashier', role_store_id: firstStore, store_ids: firstStore ? [firstStore] : [] };
    formError.value = null;
    adding.value = true;
}

async function add(): Promise<void> {
    isSaving.value = true;
    formError.value = null;
    try {
        await api.post('/members', {
            email: form.value.email.trim(),
            name: form.value.name.trim(),
            password: form.value.password || null,
            role: form.value.role,
            role_store_id: form.value.role_store_id || null,
            store_ids: form.value.store_ids,
        });
        toast('เพิ่มพนักงานแล้ว', 'success');
        adding.value = false;
        await load();
    } catch (e) {
        if (e instanceof ApiError && (e.code === 'validation_failed' || e.code === 'password_required')) {
            formError.value = e;
        } else {
            toastError(e);
        }
    } finally {
        isSaving.value = false;
    }
}

async function confirmRemove(): Promise<void> {
    if (!removing.value) {
        return;
    }
    isSaving.value = true;
    try {
        await api.delete(`/members/${removing.value.member.membership_id}/roles/${removing.value.role}`);
        toast('ถอดสิทธิ์แล้ว', 'success');
        removing.value = null;
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
                <h1 class="text-2xl font-semibold">พนักงาน</h1>
                <p class="text-sm text-slate-500">สิทธิ์ระดับสาขาใช้ได้เฉพาะสาขานั้น · สิทธิ์ทั้งร้านใช้ได้ทุกสาขาที่เข้าถึง</p>
            </div>
            <button type="button" class="btn btn-primary" @click="openAdd">+ เพิ่มพนักงาน</button>
        </header>

        <p v-if="isLoading" class="text-slate-500">กำลังโหลด…</p>
        <ul v-else class="divide-y divide-slate-100 overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200">
            <li v-for="member in members" :key="member.membership_id" class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-start">
                <div class="min-w-0 flex-1">
                    <p class="font-medium">
                        {{ member.user.name }}
                        <span v-if="member.user.id === auth.user?.id" class="text-sm font-normal text-slate-500">(คุณ)</span>
                        <span v-if="member.user.status !== 'active' || member.status !== 'active'" class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">ปิดใช้งาน</span>
                    </p>
                    <p class="truncate text-sm text-slate-500">{{ member.user.email }}</p>
                    <p class="text-xs text-slate-500">สาขา: {{ member.store_ids.map((id) => storeName.get(id) ?? 'สาขาอื่น').join(', ') || '—' }}</p>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <span v-if="member.roles.length === 0" class="text-sm text-slate-400">ไม่มีสิทธิ์</span>
                    <span v-for="(role, index) in member.roles" :key="index" class="inline-flex items-center gap-1 rounded-full bg-teal-50 py-1 pr-1 pl-3 text-sm text-teal-900 ring-1 ring-teal-200">
                        {{ roleLabel(role.role) }}<template v-if="role.store_id"> · {{ storeName.get(role.store_id) ?? 'สาขาอื่น' }}</template>
                        <button
                            v-if="member.user.id !== auth.user?.id"
                            type="button"
                            class="rounded-full px-1.5 text-teal-700 hover:bg-teal-100"
                            :aria-label="`ถอดสิทธิ์ ${roleLabel(role.role)} ของ ${member.user.name}`"
                            @click="removing = { member, role: role.role }"
                        >
                            ×
                        </button>
                    </span>
                </div>
            </li>
        </ul>

        <BaseModal v-if="adding" title="เพิ่มพนักงาน" size="lg" @close="adding = false">
            <form id="member-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="add">
                <p v-if="formError && formError.code !== 'validation_failed'" class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 sm:col-span-2" role="alert">
                    {{ formError.message }}
                </p>
                <div>
                    <label for="member-email" class="field-label">อีเมล</label>
                    <input id="member-email" v-model="form.email" type="email" required maxlength="255" class="input" />
                    <p v-if="formError?.fieldError('email')" class="mt-1 text-sm text-rose-600">{{ formError.fieldError('email') }}</p>
                </div>
                <div>
                    <label for="member-name" class="field-label">ชื่อ</label>
                    <input id="member-name" v-model="form.name" type="text" required maxlength="255" class="input" />
                </div>
                <div class="sm:col-span-2">
                    <label for="member-password" class="field-label">รหัสผ่าน (อย่างน้อย 8 ตัว · เว้นว่างได้ถ้าอีเมลนี้มีบัญชีอยู่แล้ว)</label>
                    <input id="member-password" v-model="form.password" type="password" autocomplete="new-password" minlength="8" maxlength="255" class="input" />
                    <p v-if="formError?.fieldError('password')" class="mt-1 text-sm text-rose-600">{{ formError.fieldError('password') }}</p>
                </div>
                <div>
                    <label for="member-role" class="field-label">บทบาท</label>
                    <select id="member-role" v-model="form.role" class="input">
                        <option v-for="(label, slug) in ROLE_LABELS" :key="slug" :value="slug">{{ label }}</option>
                    </select>
                </div>
                <div>
                    <label for="member-role-store" class="field-label">ขอบเขตสิทธิ์</label>
                    <select id="member-role-store" v-model="form.role_store_id" class="input">
                        <option value="">ทั้งร้าน (ทุกสาขาที่เข้าถึง)</option>
                        <option v-for="store in stores" :key="store.id" :value="store.id">เฉพาะ {{ store.name }}</option>
                    </select>
                </div>
                <fieldset class="sm:col-span-2">
                    <legend class="field-label">สาขาที่เข้าถึงได้</legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label v-for="store in stores" :key="store.id" class="flex items-center gap-3 rounded-xl px-3 py-2 ring-1 ring-slate-200">
                            <input
                                v-model="form.store_ids"
                                type="checkbox"
                                :value="store.id"
                                :disabled="store.id === form.role_store_id"
                                class="size-5 accent-teal-700"
                            />
                            <span>{{ store.name }}</span>
                        </label>
                    </div>
                </fieldset>
            </form>
            <template #footer>
                <button type="button" class="btn btn-secondary flex-1" @click="adding = false">ยกเลิก</button>
                <button type="submit" form="member-form" class="btn btn-primary flex-2" :disabled="isSaving || form.store_ids.length === 0">เพิ่มพนักงาน</button>
            </template>
        </BaseModal>

        <BaseModal v-if="removing" title="ถอดสิทธิ์" @close="removing = null">
            <p>
                ถอดบทบาท <strong>{{ roleLabel(removing.role) }}</strong> ทั้งหมดของ <strong>{{ removing.member.user.name }}</strong>?
                (รวมทุกสาขาที่ได้รับบทบาทนี้)
            </p>
            <template #footer>
                <button type="button" class="btn btn-secondary flex-1" @click="removing = null">ไม่ถอด</button>
                <button type="button" class="btn btn-danger flex-2" :disabled="isSaving" @click="confirmRemove">ถอดสิทธิ์</button>
            </template>
        </BaseModal>
    </div>
</template>
