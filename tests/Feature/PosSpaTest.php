<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Vue SPA หน้าร้าน: ทุก path ใต้ /pos ต้องได้ shell เดียวกัน เพื่อให้ vue-router จัดการ (รวมถึงตอน reload หน้า) */
class PosSpaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // ไม่ต้องใช้ asset จริงจาก Vite ใน test
        $this->withoutVite();
    }

    public function test_pos_root_serves_spa_shell(): void
    {
        $this->get('/pos')
            ->assertOk()
            ->assertSee('id="pos-app"', escape: false);
    }

    public function test_deep_links_serve_spa_shell(): void
    {
        $this->get('/pos/receipt/0199a1b2-0000-7000-8000-000000000000')
            ->assertOk()
            ->assertSee('id="pos-app"', escape: false);
    }
}
