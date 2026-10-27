/**
 * ค่าที่ shell (PosShellController) ฝังมาในหน้า — URL นี้ปักหมุดร้านไหน
 *   via=path       /pos/t/{slug}/...   → API ใช้ /api/v1/t/{slug}/...
 *   via=subdomain  {slug}.<base>/pos   → server อ่าน tenant จาก host เอง
 *   tenant=null    /pos                → เลือกร้านหลัง login แล้วส่ง X-Tenant-Id
 */
export interface PosConfig {
    tenant: { slug: string; via: 'path' | 'subdomain' } | null;
    subdomain_base: string | null;
    /** Reverb ที่ browser ต่อเข้า — null = server ไม่ได้เปิด realtime (ใช้ polling อย่างเดียว) */
    realtime: { key: string; host: string; port: number; scheme: 'http' | 'https' } | null;
}

function readConfig(): PosConfig {
    try {
        const raw = document.getElementById('pos-config')?.textContent;
        const parsed = raw ? (JSON.parse(raw) as Partial<PosConfig>) : {};

        return { tenant: parsed.tenant ?? null, subdomain_base: parsed.subdomain_base ?? null, realtime: parsed.realtime ?? null };
    } catch {
        return { tenant: null, subdomain_base: null, realtime: null };
    }
}

export const posConfig = readConfig();

/** slug ของร้านที่ URL ปักหมุดไว้ (null = เลือกร้านได้อิสระ) */
export const pinnedSlug = posConfig.tenant?.slug ?? null;

/** base ของ vue-router ตามรูปแบบ URL */
export const routerBase = posConfig.tenant?.via === 'path' ? `/pos/t/${posConfig.tenant.slug}/` : '/pos/';

/** key ของ localStorage แยกตามร้านที่ปักหมุด — เปิดหลายร้านบนเครื่องเดียว (origin เดียว) ค่าไม่ปนกัน */
export function scopedKey(key: string): string {
    return pinnedSlug ? `${key}@${pinnedSlug}` : key;
}

/** ลิงก์ประจำร้าน (ใช้แสดงในหน้าหลังร้าน) */
export function shopLinks(slug: string): { path: string; subdomain: string | null } {
    const { protocol, port, origin } = window.location;
    const portSuffix = port ? `:${port}` : '';
    const base = posConfig.subdomain_base;
    // อยู่บน subdomain ของร้าน → ลิงก์แบบ path ต้องใช้โดเมนหลัก ไม่งั้น path กับ subdomain ชี้คนละร้าน
    const rootOrigin = posConfig.tenant?.via === 'subdomain' && base ? `${protocol}//${base}${portSuffix}` : origin;

    return {
        path: `${rootOrigin}/pos/t/${slug}/`,
        subdomain: base ? `${protocol}//${slug}.${base}${portSuffix}/pos/` : null,
    };
}
