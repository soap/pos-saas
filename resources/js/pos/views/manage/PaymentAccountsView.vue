<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ApiError, api } from '@pos/api/client';
import type { PaymentAccount, PaymentProviderCode } from '@pos/api/types';
import BaseModal from '@pos/components/BaseModal.vue';
import { toast, toastError } from '@pos/lib/toast';

/** ช่องที่แต่ละ provider ต้องใช้ — ตรงกับ config pos.payments.providers.{code} */
const PROVIDERS: Record<
    PaymentProviderCode,
    { label: string; hint: string; credentials: { key: string; label: string }[]; settings: { key: string; label: string; placeholder?: string }[] }
> = {
    easyslip: {
        label: 'EasySlip (PromptPay + ตรวจสลิป)',
        hint: 'ลูกค้าโอนผ่าน QR พร้อมเพย์ของร้าน แล้วแคชเชียร์สแกนสลิปเพื่อยืนยัน',
        credentials: [
            { key: 'api_key', label: 'API key' },
            { key: 'webhook_secret', label: 'Webhook secret' },
        ],
        settings: [{ key: 'promptpay_id', label: 'PromptPay ID', placeholder: 'เบอร์มือถือ 10 หลัก หรือเลข 13 หลัก' }],
    },
    omise: {
        label: 'Omise (ยืนยันอัตโนมัติ)',
        hint: 'Omise ยืนยันการชำระผ่าน webhook โดยไม่ต้องสแกนสลิป (ขั้นต่ำ 20 บาท)',
        credentials: [
            { key: 'secret_key', label: 'Secret key (skey_…)' },
            { key: 'webhook_secret', label: 'Webhook secret' },
        ],
        settings: [{ key: 'public_key', label: 'Public key', placeholder: 'pkey_…' }],
    },
};

const accounts = ref<PaymentAccount[]>([]);
const isLoading = ref(true);
const isSaving = ref(false);
/** null = ปิด, 'new' = สร้าง, PaymentAccount = แก้ไข */
const editing = ref<PaymentAccount | 'new' | null>(null);
const form = ref({
    provider_code: 'easyslip' as PaymentProviderCode,
    name: '',
    status: 'active' as 'active' | 'inactive',
    rotateCredentials: false,
    credentials: {} as Record<string, string>,
    settings: {} as Record<string, string>,
});
const formError = ref<ApiError | null>(null);

const provider = computed(() => PROVIDERS[editing.value && editing.value !== 'new' ? editing.value.provider_code : form.value.provider_code]);
const showCredentials = computed(() => editing.value === 'new' || form.value.rotateCredentials);

async function load(): Promise<void> {
    try {
        accounts.value = (await api.get<{ data: PaymentAccount[] }>('/payment-accounts')).data;
    } catch (e) {
        toastError(e);
    } finally {
        isLoading.value = false;
    }
}

function open(account: PaymentAccount | 'new'): void {
    editing.value = account;
    formError.value = null;
    form.value =
        account === 'new'
            ? { provider_code: 'easyslip', name: '', status: 'active', rotateCredentials: false, credentials: {}, settings: {} }
            : { provider_code: account.provider_code, name: account.name, status: account.status, rotateCredentials: false, credentials: {}, settings: { ...(account.settings ?? {}) } };
}

function fieldError(key: string): string | null {
    return formError.value?.fieldError(key) ?? null;
}

async function save(): Promise<void> {
    isSaving.value = true;
    formError.value = null;
    const pick = (source: Record<string, string>, keys: { key: string }[]) => Object.fromEntries(keys.map(({ key }) => [key, (source[key] ?? '').trim()]));
    const settings = pick(form.value.settings, provider.value.settings);
    try {
        if (editing.value === 'new') {
            await api.post('/payment-accounts', {
                provider_code: form.value.provider_code,
                name: form.value.name.trim(),
                credentials: pick(form.value.credentials, provider.value.credentials),
                settings,
            });
            toast('เพิ่มบัญชีแล้ว — นำ Webhook URL ไปตั้งในระบบของผู้ให้บริการ', 'success', 8000);
        } else if (editing.value) {
            await api.patch(`/payment-accounts/${editing.value.id}`, {
                name: form.value.name.trim(),
                status: form.value.status,
                settings,
                ...(form.value.rotateCredentials ? { credentials: pick(form.value.credentials, provider.value.credentials) } : {}),
            });
            toast('บันทึกบัญชีแล้ว', 'success');
        }
        editing.value = null;
        await load();
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

async function copy(text: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        toast('คัดลอกแล้ว', 'success');
    } catch {
        toast('คัดลอกไม่ได้ กรุณาเลือกข้อความแล้วคัดลอกเอง', 'error');
    }
}

onMounted(load);
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">บัญชีรับเงิน QR</h1>
                <p class="text-sm text-slate-500">ใช้ร่วมกันได้หลายสาขา · เลือกบัญชีของแต่ละสาขาที่หน้า "สาขา" · ระบบไม่แสดงคีย์ลับหลังบันทึก</p>
            </div>
            <button type="button" class="btn btn-primary" @click="open('new')">+ เพิ่มบัญชี</button>
        </header>

        <p v-if="isLoading" class="text-slate-500">กำลังโหลด…</p>
        <p v-else-if="accounts.length === 0" class="rounded-2xl bg-white p-8 text-center text-slate-500 ring-1 ring-slate-200">
            ยังไม่มีบัญชี — สาขาจะใช้ช่องทาง QR เริ่มต้นของระบบ
        </p>
        <ul v-else class="space-y-3">
            <li v-for="account in accounts" :key="account.id" class="rounded-2xl bg-white p-4 ring-1 ring-slate-200">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="font-semibold">{{ account.name }}</p>
                        <p class="text-sm text-slate-500">{{ PROVIDERS[account.provider_code]?.label ?? account.provider_code }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="rounded-full px-2.5 py-1 text-xs" :class="account.status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'">
                            {{ account.status === 'active' ? 'ใช้งาน' : 'ปิดใช้งาน' }}
                        </span>
                        <button type="button" class="btn btn-secondary min-h-10 py-1.5 text-sm" @click="open(account)">แก้ไข</button>
                    </div>
                </div>
                <dl class="mt-3 space-y-1 text-sm">
                    <div v-for="(value, key) in account.settings ?? {}" :key="key" class="flex gap-2">
                        <dt class="w-32 shrink-0 text-slate-500">{{ key }}</dt>
                        <dd class="break-all">{{ value }}</dd>
                    </div>
                    <div class="flex gap-2">
                        <dt class="w-32 shrink-0 text-slate-500">คีย์ที่ตั้งไว้</dt>
                        <dd>{{ account.configured_credentials.join(', ') || '—' }}</dd>
                    </div>
                    <div class="flex flex-col gap-1 sm:flex-row sm:gap-2">
                        <dt class="w-32 shrink-0 text-slate-500">Webhook URL</dt>
                        <dd class="flex min-w-0 flex-1 items-center gap-2">
                            <code class="min-w-0 flex-1 truncate rounded bg-slate-100 px-2 py-1 text-xs">{{ account.webhook_url }}</code>
                            <button type="button" class="shrink-0 text-sm font-medium text-teal-700 hover:underline" @click="copy(account.webhook_url)">คัดลอก</button>
                        </dd>
                    </div>
                </dl>
            </li>
        </ul>

        <BaseModal v-if="editing" :title="editing === 'new' ? 'เพิ่มบัญชีรับเงิน' : `แก้ไข ${editing.name}`" size="lg" @close="editing = null">
            <form id="account-form" class="space-y-4" @submit.prevent="save">
                <div v-if="editing === 'new'">
                    <label for="account-provider" class="field-label">ผู้ให้บริการ</label>
                    <select id="account-provider" v-model="form.provider_code" class="input">
                        <option v-for="(item, code) in PROVIDERS" :key="code" :value="code">{{ item.label }}</option>
                    </select>
                    <p class="mt-1 text-sm text-slate-500">{{ provider.hint }}</p>
                </div>
                <div>
                    <label for="account-name" class="field-label">ชื่อบัญชี (สำหรับเรียกในระบบ)</label>
                    <input id="account-name" v-model="form.name" type="text" required maxlength="255" class="input" placeholder="เช่น บัญชีหลัก สาขากรุงเทพ" />
                </div>
                <div v-for="field in provider.settings" :key="field.key">
                    <label :for="`setting-${field.key}`" class="field-label">{{ field.label }}</label>
                    <input :id="`setting-${field.key}`" v-model="form.settings[field.key]" type="text" required class="input" :placeholder="field.placeholder" />
                    <p v-if="fieldError(`settings.${field.key}`)" class="mt-1 text-sm text-rose-600">{{ fieldError(`settings.${field.key}`) }}</p>
                </div>
                <label v-if="editing !== 'new'" class="flex items-center gap-3">
                    <input v-model="form.status" type="checkbox" true-value="active" false-value="inactive" class="size-5 accent-teal-700" />
                    <span>เปิดใช้งาน</span>
                </label>
                <label v-if="editing !== 'new'" class="flex items-center gap-3">
                    <input v-model="form.rotateCredentials" type="checkbox" class="size-5 accent-teal-700" />
                    <span>เปลี่ยนคีย์ลับ (ต้องกรอกใหม่ทุกช่อง)</span>
                </label>
                <template v-if="showCredentials">
                    <div v-for="field in provider.credentials" :key="field.key">
                        <label :for="`credential-${field.key}`" class="field-label">{{ field.label }}</label>
                        <input :id="`credential-${field.key}`" v-model="form.credentials[field.key]" type="password" autocomplete="off" required class="input" />
                        <p v-if="fieldError(`credentials.${field.key}`)" class="mt-1 text-sm text-rose-600">{{ fieldError(`credentials.${field.key}`) }}</p>
                    </div>
                </template>
            </form>
            <template #footer>
                <button type="button" class="btn btn-secondary flex-1" @click="editing = null">ยกเลิก</button>
                <button type="submit" form="account-form" class="btn btn-primary flex-2" :disabled="isSaving">บันทึก</button>
            </template>
        </BaseModal>
    </div>
</template>
