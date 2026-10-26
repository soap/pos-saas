/** localStorage ที่ไม่ throw (private mode / ถูกบล็อก) — ใช้เก็บ token และสาขาที่เลือกไว้เท่านั้น */
export const storage = {
    get(key: string): string | null {
        try {
            return window.localStorage.getItem(key);
        } catch {
            return null;
        }
    },

    set(key: string, value: string | null): void {
        try {
            if (value === null) {
                window.localStorage.removeItem(key);
            } else {
                window.localStorage.setItem(key, value);
            }
        } catch {
            // ไม่มี storage ก็ยังใช้งานได้ แค่ต้อง login ใหม่เมื่อ reload
        }
    },
};
