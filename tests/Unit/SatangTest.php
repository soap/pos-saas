<?php

namespace Tests\Unit;

use Akaunting\Money\Currency;
use Akaunting\Money\Money;
use App\Support\Money\Satang;
use InvalidArgumentException;
use Tests\TestCase;

/** spec §2: เงินเป็นจำนวนเต็มสตางค์ — ตรวจตัวเชื่อมกับ akaunting/laravel-money (ไม่ใช้ฐานข้อมูล) */
class SatangTest extends TestCase
{
    public function test_arithmetic_round_trips_to_exact_integer(): void
    {
        $line = Satang::money(5000)->add(Satang::money(1000))->multiply(3)->subtract(Satang::money(500));

        $this->assertSame(17500, Satang::of($line));
        $this->assertSame(350, Satang::of(Satang::sum([100, Satang::money(250)])));
        $this->assertSame(0, Satang::of(Satang::sum([])));
    }

    public function test_fractional_satang_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Satang::of(Satang::money(1001)->divide(2));
    }

    public function test_other_currency_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Satang::of(new Money(100, new Currency('USD')));
    }

    public function test_min_max_and_comparisons(): void
    {
        $this->assertSame(0, Satang::of(Satang::max(Satang::zero(), Satang::money(-300))));
        $this->assertSame(-300, Satang::of(Satang::min(Satang::zero(), Satang::money(-300))));
        $this->assertTrue(Satang::money(11000)->subtract(Satang::money(10000))->isPositive());
    }

    public function test_formatting(): void
    {
        $this->assertSame('1,234.50', Satang::format(123450));
        $this->assertSame('-0.05', Satang::format(-5));
        $this->assertSame(['satang' => 123450, 'display' => '฿1,234.50'], Satang::present(123450));
    }
}
