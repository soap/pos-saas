import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { api, setRequestContext } from '@pos/api/client';
import type { LoginResponse, MeResponse, TenantSummary, User } from '@pos/api/types';
import { storage } from '@pos/lib/storage';

const TOKEN_KEY = 'pos.token';

export const useAuthStore = defineStore('auth', () => {
    const token = ref<string | null>(storage.get(TOKEN_KEY));
    const user = ref<User | null>(null);
    const tenants = ref<TenantSummary[]>([]);

    setRequestContext({ token: token.value });

    const isAuthenticated = computed(() => token.value !== null);

    function setToken(value: string | null): void {
        token.value = value;
        storage.set(TOKEN_KEY, value);
        setRequestContext({ token: value });
    }

    async function login(email: string, password: string): Promise<void> {
        const response = await api.post<LoginResponse>('/auth/login', { email, password, device_name: 'pos-web' });
        setToken(response.token);
        user.value = response.user;
        await fetchMe();
    }

    async function fetchMe(): Promise<void> {
        const response = await api.get<MeResponse>('/me');
        user.value = response.user;
        tenants.value = response.tenants;
    }

    /** ล้าง state ฝั่ง client เสมอ แม้เรียก API logout ไม่สำเร็จ (เช่น token หมดอายุแล้ว) */
    async function logout(): Promise<void> {
        try {
            if (token.value) {
                await api.post('/auth/logout');
            }
        } catch {
            // token อาจใช้ไม่ได้แล้ว — ไม่ต้องทำอะไรเพิ่ม
        } finally {
            clear();
        }
    }

    function clear(): void {
        setToken(null);
        user.value = null;
        tenants.value = [];
    }

    return { token, user, tenants, isAuthenticated, login, fetchMe, logout, clear };
});
