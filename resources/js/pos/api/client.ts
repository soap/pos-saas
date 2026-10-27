import axios, { AxiosError, type AxiosRequestConfig } from 'axios';
import { posConfig } from '@pos/lib/config';

/**
 * HTTP client กลางของ POS — แนบ Bearer token, X-Tenant-Id, X-Store-Id ให้ทุก request
 * และแปลง error envelope ของ API ({ error: { code, message, details } }) เป็น ApiError
 */

export interface RequestContext {
    token: string | null;
    tenantId: string | null;
    storeId: string | null;
}

let context: RequestContext = { token: null, tenantId: null, storeId: null };
let onUnauthenticated: () => void = () => {};

export function setRequestContext(next: Partial<RequestContext>): void {
    context = { ...context, ...next };
}

export function setUnauthenticatedHandler(handler: () => void): void {
    onUnauthenticated = handler;
}

let onSubscriptionInactive: () => void = () => {};

/** แพ็กเกจหมดอายุ/ถูกระงับ (402) — พาไปหน้าชำระค่าแพ็กเกจ */
export function setSubscriptionInactiveHandler(handler: () => void): void {
    onSubscriptionInactive = handler;
}

export class ApiError extends Error {
    constructor(
        public readonly status: number,
        public readonly code: string,
        message: string,
        public readonly details: Record<string, unknown> = {},
    ) {
        super(message);
    }

    /** ข้อความ validation ข้อแรกของ field (422 validation_failed) */
    fieldError(field: string): string | null {
        const messages = this.details[field];

        return Array.isArray(messages) && typeof messages[0] === 'string' ? messages[0] : null;
    }

    get isNetworkError(): boolean {
        return this.status === 0;
    }
}

const http = axios.create({
    baseURL: '/api/v1',
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    timeout: 20000,
});

/** route ระดับผู้ใช้ (ไม่ผูก tenant) — ไม่เติม /t/{slug} */
const IDENTITY_ROUTE = /^\/(auth\/|me$|tenants$|admin\/)/;

http.interceptors.request.use((config) => {
    if (context.token) {
        config.headers.set('Authorization', `Bearer ${context.token}`);
    }
    const pinned = posConfig.tenant;
    if (pinned?.via === 'path' && config.url && !IDENTITY_ROUTE.test(config.url)) {
        config.url = `/t/${pinned.slug}${config.url}`;
    }
    // URL ปักหมุดร้านแล้ว (path/subdomain) ให้ server เลือก tenant จาก URL — ไม่ส่ง header ซ้อน
    if (context.tenantId && !pinned) {
        config.headers.set('X-Tenant-Id', context.tenantId);
    }
    if (context.storeId) {
        config.headers.set('X-Store-Id', context.storeId);
    }

    return config;
});

function toApiError(error: unknown): ApiError {
    if (!(error instanceof AxiosError)) {
        return new ApiError(0, 'unknown', 'เกิดข้อผิดพลาดที่ไม่คาดคิด');
    }
    if (!error.response) {
        return new ApiError(0, 'network_error', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาตรวจสอบอินเทอร์เน็ต');
    }

    const body = error.response.data as { error?: { code?: string; message?: string; details?: Record<string, unknown> } } | undefined;

    return new ApiError(
        error.response.status,
        body?.error?.code ?? 'http_error',
        body?.error?.message ?? 'เกิดข้อผิดพลาด',
        body?.error?.details ?? {},
    );
}

async function request<T>(config: AxiosRequestConfig): Promise<T> {
    try {
        const response = await http.request<T>(config);

        return response.data;
    } catch (error) {
        const apiError = toApiError(error);
        if (apiError.status === 401 && context.token) {
            onUnauthenticated();
        }
        if (apiError.status === 402 && apiError.code === 'subscription_inactive') {
            onSubscriptionInactive();
        }
        throw apiError;
    }
}

export function newIdempotencyKey(): string {
    return crypto.randomUUID();
}

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

export const api = {
    get: <T>(url: string, params?: Record<string, unknown>) => request<T>({ method: 'GET', url, params }),
    post: <T>(url: string, data?: unknown) => request<T>({ method: 'POST', url, data }),
    patch: <T>(url: string, data?: unknown) => request<T>({ method: 'PATCH', url, data }),
    put: <T>(url: string, data?: unknown) => request<T>({ method: 'PUT', url, data }),
    delete: <T>(url: string) => request<T>({ method: 'DELETE', url }),

    /**
     * POST ที่ server บังคับ Idempotency-Key — ส่งซ้ำด้วย key เดิมเมื่อเน็ตหลุดหรือคำสั่งเดิมยังประมวลผลอยู่
     * server จะ replay response เดิม จึงไม่เกิดออเดอร์/การชำระซ้ำ
     */
    async postIdempotent<T>(url: string, data: unknown, idempotencyKey: string, attempts = 3): Promise<T> {
        for (let attempt = 1; ; attempt++) {
            try {
                return await request<T>({ method: 'POST', url, data, headers: { 'Idempotency-Key': idempotencyKey } });
            } catch (error) {
                const retryable = error instanceof ApiError && (error.isNetworkError || error.code === 'idempotency_in_progress');
                if (!retryable || attempt >= attempts) {
                    throw error;
                }
                await sleep(600 * attempt);
            }
        }
    },
};
