<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Vue SPA หน้าร้าน: ทุก path ใต้ /pos ได้ shell เดียวกัน (vue-router จัดการต่อ รวมถึงตอน reload)
 * และ shell บอก SPA ว่า URL นี้ปักหมุดร้านไหน (path /pos/t/{slug} หรือ subdomain)
 */
class PosSpaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // ไม่ต้องใช้ asset จริงจาก Vite ใน test
        $this->withoutVite();
        config(['pos.tenancy.subdomain_base' => 'pos.test']);
    }

    /** @return array<string, mixed> */
    private function shellConfig(string $url): array
    {
        $html = $this->get($url)->assertOk()->assertSee('id="pos-app"', escape: false)->getContent();
        preg_match('#<script id="pos-config" type="application/json">(.*?)</script>#s', $html, $match);

        return json_decode($match[1] ?? 'null', true);
    }

    public function test_unpinned_shell_and_deep_links(): void
    {
        $this->assertNull($this->shellConfig('/pos')['tenant']);
        $this->assertNull($this->shellConfig('/pos/receipt/0199a1b2-0000-7000-8000-000000000000')['tenant']);
        $this->assertSame('pos.test', $this->shellConfig('/pos')['subdomain_base']);
    }

    public function test_path_pins_tenant_including_deep_links(): void
    {
        $this->assertSame(['slug' => 'tenant-a', 'via' => 'path'], $this->shellConfig('/pos/t/tenant-a')['tenant']);
        $this->assertSame(['slug' => 'tenant-a', 'via' => 'path'], $this->shellConfig('/pos/t/tenant-a/manage/products')['tenant']);
    }

    public function test_subdomain_pins_tenant(): void
    {
        $this->assertSame(['slug' => 'tenant-a', 'via' => 'subdomain'], $this->shellConfig('http://tenant-a.pos.test/pos/kitchen')['tenant']);
        // subdomain ของระบบไม่ถือเป็นร้าน
        $this->assertNull($this->shellConfig('http://app.pos.test/pos')['tenant']);
    }

    public function test_invalid_reserved_or_conflicting_slugs_are_rejected(): void
    {
        $this->get('/pos/t/Tenant_A')->assertNotFound();
        $this->get('/pos/t/www')->assertNotFound();
        $this->get('http://tenant-b.pos.test/pos/t/tenant-a')->assertStatus(400);
        $this->assertSame('tenant-a', $this->shellConfig('http://tenant-a.pos.test/pos/t/tenant-a')['tenant']['slug']);
    }

    public function test_shell_does_not_reveal_whether_tenant_exists(): void
    {
        // ไม่มีร้านนี้ในฐานข้อมูล แต่ยังได้ shell ปกติ — สิทธิ์ตรวจที่ API หลัง login
        $this->assertSame('no-such-shop', $this->shellConfig('/pos/t/no-such-shop')['tenant']['slug']);
    }
}
