<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ApiError, api } from '@pos/api/client';
import type { ManagedStore, PaymentAccount } from '@pos/api/types';
import BaseModal from '@pos/components/BaseModal.vue';
import { shopLinks } from '@pos/lib/config';
import { toast, toastError } from '@pos/lib/toast';
import { useAuthStore } from '@pos/stores/auth';
import { useSessionStore } from '@pos/stores/session';

const auth = useAuthStore();
const session = useSessionStore();

const stores = ref<ManagedStore[]>([]);
const accounts = ref<PaymentAccount[]>([]);
const isLoading = ref(true);
const creating = ref(false);
const form = ref({ code: '', name: '', timezone: 'Asia/Bangkok', kitchen_enabled: true, tax_mode: 'none' as 'none' | 'inclusive' });
const formError = ref<ApiError | null>(null);
const isSaving = ref(false);
const assigningStoreId = ref<string | null>(null);
const editingStore = ref<ManagedStore | null>(null);
const editForm = ref({ name: '', status: 'active' as 'active' | 'inactive', kitchen_enabled: true, tax_mode: 'none' as 'none' | 'inclusive' });

/** ลิงก์ที่ปักหมุดร้านนี้ — ให้พนักงานบุ๊กมาร์ก/ตั้งเป็นหน้าแรกของแท็บเล็ตร้าน */
const links = computed(() => {
    const slug = auth.tenants.find((t) => t.id === session.tenantId)?.slug;

    return slug ? shopLinks(slug) : null;
});

async function copyLink(url: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(url);
        toast('คัดลอกลิงก์แล้ว', 'success');
    } catch {
        toast('คัดลอกไม่ได้ กรุณาเลือกข้อความแล้วคัดลอกเอง', 'error');
    }
}

const canConfigurePayments = computed(() => session.can('payments.configure'));
const activeAccounts = computed(() => accounts.value.filter((a) => a.status === 'active'));
const accountName = computed(() => new Map(accounts.value.map((a) => [a.id, a.name])));

async function load(): Promise<void> {
    try {
        const [storeResponse, accountResponse] = await Promise.all([
            api.get<{ data: ManagedStore[] }>('/stores'),
            canConfigurePayments.value ? api.get<{ data: PaymentAccount[] }>('/payment-accounts') : Promise.resolve({ data: [] }),
        ]);
        stores.value = storeResponse.data;
        accounts.value = accountResponse.data;
    } catch (e) {
        toastError(e);
    } finally {
        isLoading.value = false;
    }
}

function openCreate(): void {
    form.value = { code: '', name: '', timezone: 'Asia/Bangkok', kitchen_enabled: true, tax_mode: 'none' };
    formError.value = null;
    creating.value = true;
}

async function create(): Promise<void> {
    isSaving.value = true;
    formError.value = null;
    try {
        await api.post('/stores', {
            code: form.value.code.trim(),
            name: form.value.name.trim(),
            timezone: form.value.timezone,
            settings: { kitchen_enabled: form.value.kitchen_enabled, tax_mode: form.value.tax_mode },
        });
        toast('เพิ่มสาขาแล้ว', 'success');
        creating.value = false;
        // ให้หน้าเลือกสาขาเห็นสาขาใหม่ทันที
        await Promise.all([load(), auth.fetchMe()]);
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

function openEdit(store: ManagedStore): void {
    editingStore.value = store;
    editForm.value = {
        name: store.name,
        status: store.status === 'inactive' ? 'inactive' : 'active',
        kitchen_enabled: store.settings?.kitchen_enabled ?? true,
        tax_mode: store.settings?.tax_mode ?? 'none',
    };
}

async function saveEdit(): Promise<void> {
    const store = editingStore.value;
    if (!store) {
        return;
    }
    isSaving.value = true;
    try {
        await api.patch(`/stores/${store.id}`, {
            name: editForm.value.name.trim(),
            status: editForm.value.status,
            settings: { kitchen_enabled: editForm.value.kitchen_enabled, tax_mode: editForm.value.tax_mode },
        });
        toast('บันทึกสาขาแล้ว', 'success');
        editingStore.value = null;
        // ชื่อสาขา/การใช้ครัวของสาขาปัจจุบันมีผลกับหน้าขายทันที
        await Promise.all([load(), auth.fetchMe(), store.id === session.storeId ? session.load() : null]);
    } catch (e) {
        toastError(e);
    } finally {
        isSaving.value = false;
    }
}

async function assignAccount(store: ManagedStore, accountId: string): Promise<void> {
    assigningStoreId.value = store.id;
    try {
        await api.put(`/stores/${store.id}/qr-payment-account`, { payment_account_id: accountId || null });
        store.qr_payment_account_id = accountId || null;
        toast(`เปลี่ยนบัญชีรับ QR ของ ${store.name} แล้ว`, 'success');
    } catch (e) {
        toastError(e);
        await load();
    } finally {
        assigningStoreId.value = null;
    }
}

onMounted(load);
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">สาขา</h1>
                <p class="text-sm text-slate-500">สาขาที่คุณมีสิทธิ์เข้าถึง</p>
            </div>
            <button v-if="session.can('stores.manage')" type="button" class="btn btn-primary" @click="openCreate">+ เพิ่มสาขา</button>
        </header>

        <section v-if="links" class="space-y-2 rounded-2xl bg-white p-4 ring-1 ring-slate-200">
            <h2 class="font-semibold">ลิงก์ประจำร้าน</h2>
            <p class="text-sm text-slate-500">เปิดลิงก์นี้แล้วระบบจะเข้าร้านนี้ทันที — ใช้บุ๊กมาร์กหรือตั้งเป็นหน้าแรกของแท็บเล็ตในร้าน</p>
            <div v-for="url in [links.subdomain, links.path].filter((u): u is string => u !== null)" :key="url" class="flex items-center gap-2">
                <code class="min-w-0 flex-1 truncate rounded bg-slate-100 px-2 py-1.5 text-sm">{{ url }}</code>
                <button type="button" class="shrink-0 text-sm font-medium text-teal-700 hover:underline" @click="copyLink(url)">คัดลอก</button>
            </div>
        </section>

        <p v-if="isLoading" class="text-slate-500">กำลังโหลด…</p>
        <ul v-else class="space-y-3">
            <li v-for="store in stores" :key="store.id" class="rounded-2xl bg-white p-4 ring-1 ring-slate-200">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="font-semibold">{{ store.name }} <span class="font-normal text-slate-500">({{ store.code }})</span></p>
                        <p class="text-sm text-slate-500">{{ store.timezone }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5 text-xs">
                        <span v-if="store.status !== 'active'" class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-600">ปิดใช้งาน</span>
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">
                            {{ store.settings?.kitchen_enabled === false ? 'ไม่ใช้ครัว' : 'ใช้จอครัว' }}
                        </span>
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">
                            {{ store.settings?.tax_mode === 'inclusive' ? 'ราคารวม VAT' : 'ไม่คิด VAT' }}
                        </span>
                        <button
                            v-if="session.can('stores.manage')"
                            type="button"
                            class="ml-1 rounded-lg px-2 py-1 text-sm font-medium text-teal-700 hover:bg-teal-50"
                            @click="openEdit(store)"
                        >
                            แก้ไข
                        </button>
                    </div>
                </div>

                <div v-if="canConfigurePayments" class="mt-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-3">
                    <label :for="`qr-${store.id}`" class="text-sm font-medium text-slate-700 sm:w-32">บัญชีรับ QR</label>
                    <select
                        :id="`qr-${store.id}`"
                        :value="store.qr_payment_account_id ?? ''"
                        class="input py-2 sm:max-w-sm"
                        :disabled="assigningStoreId === store.id"
                        @change="assignAccount(store, ($event.target as HTMLSelectElement).value)"
                    >
                        <option value="">ค่าเริ่มต้นของระบบ</option>
                        <option
                            v-if="store.qr_payment_account_id && !activeAccounts.some((a) => a.id === store.qr_payment_account_id)"
                            :value="store.qr_payment_account_id"
                            disabled
                        >
                            {{ accountName.get(store.qr_payment_account_id) ?? 'บัญชีที่ปิดใช้งาน' }} (ปิดใช้งาน)
                        </option>
                        <option v-for="account in activeAccounts" :key="account.id" :value="account.id">
                            {{ account.name }} · {{ account.provider_code === 'omise' ? 'Omise' : 'EasySlip' }}
                        </option>
                    </select>
                </div>
            </li>
        </ul>

        <BaseModal v-if="editingStore" :title="`แก้ไขสาขา ${editingStore.code}`" @close="editingStore = null">
            <form id="edit-store-form" class="space-y-4" @submit.prevent="saveEdit">
                <p class="text-sm text-slate-500">รหัสสาขาและเขตเวลาแก้ไม่ได้ (ผูกกับเลขออเดอร์และวันทำการ)</p>
                <div>
                    <label for="edit-store-name" class="field-label">ชื่อสาขา</label>
                    <input id="edit-store-name" v-model="editForm.name" type="text" required maxlength="255" class="input" />
                </div>
                <div>
                    <label for="edit-store-tax" class="field-label">ภาษีมูลค่าเพิ่ม (มีผลกับออเดอร์ใหม่)</label>
                    <select id="edit-store-tax" v-model="editForm.tax_mode" class="input">
                        <option value="none">ไม่คิด VAT</option>
                        <option value="inclusive">ราคารวม VAT แล้ว</option>
                    </select>
                </div>
                <label class="flex items-center gap-3">
                    <input v-model="editForm.kitchen_enabled" type="checkbox" class="size-5 accent-teal-700" />
                    <span>ใช้จอครัว (ต้องส่งครัวก่อนปิดออเดอร์)</span>
                </label>
                <label class="flex items-center gap-3">
                    <input
                        v-model="editForm.status"
                        type="checkbox"
                        true-value="active"
                        false-value="inactive"
                        class="size-5 accent-teal-700"
                        :disabled="editingStore.id === session.storeId"
                    />
                    <span>
                        เปิดใช้งานสาขา
                        <span v-if="editingStore.id === session.storeId" class="block text-xs text-slate-500">ปิดสาขาที่กำลังใช้งานอยู่ไม่ได้</span>
                    </span>
                </label>
            </form>
            <template #footer>
                <button type="button" class="btn btn-secondary flex-1" @click="editingStore = null">ยกเลิก</button>
                <button type="submit" form="edit-store-form" class="btn btn-primary flex-2" :disabled="isSaving || editForm.name.trim() === ''">บันทึก</button>
            </template>
        </BaseModal>

        <BaseModal v-if="creating" title="เพิ่มสาขา" @close="creating = false">
            <form id="store-form" class="space-y-4" @submit.prevent="create">
                <div>
                    <label for="store-code" class="field-label">รหัสสาขา (a-z, 0-9, -, _)</label>
                    <input id="store-code" v-model="form.code" type="text" required maxlength="32" pattern="[A-Za-z0-9_\-]+" class="input" placeholder="เช่น BKK01" />
                    <p v-if="formError?.fieldError('code')" class="mt-1 text-sm text-rose-600">{{ formError.fieldError('code') }}</p>
                </div>
                <div>
                    <label for="store-name" class="field-label">ชื่อสาขา</label>
                    <input id="store-name" v-model="form.name" type="text" required maxlength="255" class="input" />
                </div>
                <div>
                    <label for="store-timezone" class="field-label">เขตเวลา</label>
                    <input id="store-timezone" v-model="form.timezone" type="text" required class="input" />
                    <p v-if="formError?.fieldError('timezone')" class="mt-1 text-sm text-rose-600">{{ formError.fieldError('timezone') }}</p>
                </div>
                <div>
                    <label for="store-tax" class="field-label">ภาษีมูลค่าเพิ่ม</label>
                    <select id="store-tax" v-model="form.tax_mode" class="input">
                        <option value="none">ไม่คิด VAT</option>
                        <option value="inclusive">ราคารวม VAT แล้ว</option>
                    </select>
                </div>
                <label class="flex items-center gap-3">
                    <input v-model="form.kitchen_enabled" type="checkbox" class="size-5 accent-teal-700" />
                    <span>ใช้จอครัว (ต้องส่งครัวก่อนปิดออเดอร์)</span>
                </label>
            </form>
            <template #footer>
                <button type="button" class="btn btn-secondary flex-1" @click="creating = false">ยกเลิก</button>
                <button type="submit" form="store-form" class="btn btn-primary flex-2" :disabled="isSaving">เพิ่มสาขา</button>
            </template>
        </BaseModal>
    </div>
</template>
