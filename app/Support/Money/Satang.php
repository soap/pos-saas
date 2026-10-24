<?php

namespace App\Support\Money;

use Akaunting\Money\Currency;
use Akaunting\Money\Money;
use InvalidArgumentException;

/**
 * จุดเชื่อมระหว่างฐานข้อมูล/API (BIGINT หน่วยสตางค์ — spec §2) กับ akaunting/laravel-money
 *
 * - คำนวณเงินใน service ด้วย Money (THB, amount = สตางค์, ไม่ convert)
 * - ก่อนบันทึก/ตอบ API ต้องแปลงกลับด้วย Satang::of() ซึ่งคืน int เสมอ
 *   (Money::add/subtract/multiply คืนค่า float ภายใน — ห้ามเก็บค่า float ลง DB)
 */
final class Satang
{
    public const CURRENCY = 'THB';

    public static function money(int $satang): Money
    {
        return new Money($satang, new Currency(self::CURRENCY));
    }

    public static function zero(): Money
    {
        return self::money(0);
    }

    /** แปลง Money กลับเป็นจำนวนเต็มสตางค์ — ปฏิเสธเศษสตางค์หรือสกุลเงินอื่น */
    public static function of(Money $money): int
    {
        if ($money->getCurrency()->getCurrency() !== self::CURRENCY) {
            throw new InvalidArgumentException('Unsupported currency '.$money->getCurrency()->getCurrency());
        }
        $amount = $money->getAmount();
        if ((float) $amount !== floor((float) $amount)) {
            throw new InvalidArgumentException("Amount {$amount} is not a whole number of satang");
        }

        return (int) $amount;
    }

    /** @param  iterable<Money|int>  $amounts */
    public static function sum(iterable $amounts): Money
    {
        $total = self::zero();
        foreach ($amounts as $amount) {
            $total = $total->add($amount instanceof Money ? $amount : self::money($amount));
        }

        return $total;
    }

    public static function max(Money $a, Money $b): Money
    {
        return $a->greaterThanOrEqual($b) ? $a : $b;
    }

    public static function min(Money $a, Money $b): Money
    {
        return $a->lessThanOrEqual($b) ? $a : $b;
    }

    /** ข้อความบาทสำหรับใบเสร็จ เช่น 1,234.50 (ไม่มีสัญลักษณ์ ฿ — เครื่องพิมพ์ ESC/POS หลายรุ่นพิมพ์ไม่ได้) */
    public static function format(int|Money $amount): string
    {
        return ($amount instanceof Money ? $amount : self::money($amount))->formatSimple();
    }

    /** สำหรับ response: แสดงทั้งค่าสตางค์และข้อความ เช่น ฿1,234.50 */
    public static function present(int|Money $amount): array
    {
        $money = $amount instanceof Money ? $amount : self::money($amount);

        return ['satang' => self::of($money), 'display' => $money->format()];
    }
}
