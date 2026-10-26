<?php

namespace App\Modules\Payments\Services;

use InvalidArgumentException;

/**
 * สร้าง EMVCo payload ของ PromptPay QR (Thai QR Payment — Credit Transfer)
 * รองรับ PromptPay ID แบบเบอร์มือถือ (10 หลัก) และเลขประจำตัวประชาชน/ผู้เสียภาษี (13 หลัก)
 */
final class PromptPayPayload
{
    private const AID = 'A000000677010111';

    public static function isValidId(string $promptPayId): bool
    {
        return (bool) preg_match('/^(0\d{9}|\d{13})$/', self::digits($promptPayId));
    }

    public static function build(string $promptPayId, int $amountSatang): string
    {
        $id = self::digits($promptPayId);
        if (! self::isValidId($id)) {
            throw new InvalidArgumentException('PromptPay ID ต้องเป็นเบอร์มือถือ 10 หลักหรือเลข 13 หลัก');
        }
        // เบอร์มือถือ 0812345678 → 0066812345678 (tag 01), เลข 13 หลักใช้ตรง ๆ (tag 02)
        $account = strlen($id) === 10
            ? self::tlv('01', '0066'.substr($id, 1))
            : self::tlv('02', $id);

        $payload = self::tlv('00', '01')
            .self::tlv('01', '12') // dynamic QR (มียอดเงิน ใช้ครั้งเดียว)
            .self::tlv('29', self::tlv('00', self::AID).$account)
            .self::tlv('53', '764')
            .self::tlv('54', sprintf('%d.%02d', intdiv($amountSatang, 100), $amountSatang % 100))
            .self::tlv('58', 'TH')
            .'6304';

        return $payload.sprintf('%04X', self::crc16($payload));
    }

    private static function digits(string $value): string
    {
        return preg_replace('/\D/', '', $value);
    }

    private static function tlv(string $tag, string $value): string
    {
        return $tag.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }

    /** CRC-16/CCITT-FALSE ตามมาตรฐาน EMV QR */
    private static function crc16(string $data): int
    {
        $crc = 0xFFFF;
        for ($i = 0; $i < strlen($data); $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return $crc;
    }
}
