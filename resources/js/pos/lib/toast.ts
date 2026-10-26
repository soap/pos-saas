import { reactive } from 'vue';
import { ApiError } from '@pos/api/client';

export interface Toast {
    id: number;
    kind: 'success' | 'error' | 'info';
    message: string;
}

let nextId = 1;

export const toasts = reactive<Toast[]>([]);

export function toast(message: string, kind: Toast['kind'] = 'info', timeoutMs = 4000): void {
    const id = nextId++;
    toasts.push({ id, kind, message });
    setTimeout(() => dismissToast(id), timeoutMs);
}

export function dismissToast(id: number): void {
    const index = toasts.findIndex((t) => t.id === id);
    if (index >= 0) {
        toasts.splice(index, 1);
    }
}

/** ข้อความที่แสดงให้ผู้ใช้ — ใช้ message ภาษาไทยจาก API ถ้ามี */
export function errorMessage(error: unknown): string {
    if (error instanceof ApiError) {
        const firstField = Object.values(error.details).find(Array.isArray) as unknown[] | undefined;

        return error.code === 'validation_failed' && typeof firstField?.[0] === 'string' ? firstField[0] : error.message;
    }

    return 'เกิดข้อผิดพลาดที่ไม่คาดคิด';
}

export function toastError(error: unknown): void {
    toast(errorMessage(error), 'error', 6000);
}
