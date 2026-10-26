const formatter = new Intl.NumberFormat('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** 12350 → "123.50" */
export function formatSatang(satang: number): string {
    return formatter.format(satang / 100);
}

/** 12350 → "฿123.50" */
export function formatBaht(satang: number): string {
    return `฿${formatSatang(satang)}`;
}

/** "123.5" → 12350; ข้อความที่ไม่ใช่จำนวนเงินที่ถูกต้อง → null (ไม่ใช้ float คูณเพื่อเลี่ยงปัดเศษผิด) */
export function parseBahtToSatang(input: string): number | null {
    const match = input.trim().replace(/,/g, '').match(/^(\d{1,9})(?:\.(\d{0,2}))?$/);
    if (!match) {
        return null;
    }

    return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
}
