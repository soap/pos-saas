/**
 * เสียงแจ้งเตือนจอครัว + กันจอดับ
 * - browser เล่นเสียงได้หลังผู้ใช้แตะหน้าจอแล้วเท่านั้น จึงต้องเรียก unlockSound() จากปุ่ม
 * - Wake Lock เป็นแค่ตัวช่วย: ต้องตั้งค่า kiosk/display ของอุปกรณ์ด้วย (ภาคผนวก D5)
 */

let audio: AudioContext | null = null;

export async function unlockSound(): Promise<boolean> {
    try {
        audio ??= new AudioContext();
        if (audio.state === 'suspended') {
            await audio.resume();
        }

        return audio.state === 'running';
    } catch {
        return false;
    }
}

export function isSoundUnlocked(): boolean {
    return audio?.state === 'running';
}

/** เสียง "ติ๊ง-ด่อง" สองโน้ต ได้ยินชัดในครัวที่มีเสียงรบกวน */
export function playChime(): void {
    if (!audio || audio.state !== 'running') {
        return;
    }
    const start = audio.currentTime;
    [
        { frequency: 1046.5, at: 0 },
        { frequency: 784, at: 0.22 },
    ].forEach(({ frequency, at }) => {
        const oscillator = audio!.createOscillator();
        const gain = audio!.createGain();
        oscillator.type = 'triangle';
        oscillator.frequency.value = frequency;
        gain.gain.setValueAtTime(0.0001, start + at);
        gain.gain.exponentialRampToValueAtTime(0.6, start + at + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + at + 0.45);
        oscillator.connect(gain).connect(audio!.destination);
        oscillator.start(start + at);
        oscillator.stop(start + at + 0.5);
    });
}

let wakeLock: WakeLockSentinel | null = null;

/** ขอ wake lock (ถ้า browser รองรับ) — ต้องขอใหม่ทุกครั้งที่แท็บกลับมา visible */
export async function keepScreenAwake(): Promise<void> {
    if (!('wakeLock' in navigator) || document.visibilityState !== 'visible') {
        return;
    }
    try {
        wakeLock = await navigator.wakeLock.request('screen');
    } catch {
        // แบตต่ำ/นโยบาย browser ไม่อนุญาต — พึ่งการตั้งค่าอุปกรณ์แทน
    }
}

export async function releaseScreenAwake(): Promise<void> {
    await wakeLock?.release().catch(() => {});
    wakeLock = null;
}
