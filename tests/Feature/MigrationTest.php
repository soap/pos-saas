<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** spec §10 Migration: สร้างฐานข้อมูลใหม่ได้ตั้งแต่ต้น; rollback ตาม dependency order */
class MigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // test นี้รื้อ schema → ให้ test class ถัดไปที่ใช้ RefreshDatabase migrate ใหม่เสมอ
        $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
    }

    public function test_full_rollback_drops_every_table_and_schema_can_be_rebuilt(): void
    {
        $this->artisan('migrate:fresh')->assertSuccessful();

        $this->artisan('migrate:reset')->assertSuccessful();
        $this->assertSame(['migrations'], Schema::getTableListing(schemaQualified: false));

        $this->artisan('migrate')->assertSuccessful();
        $this->assertTrue(Schema::hasTable('outbox_events'));
        $this->assertTrue(Schema::hasTable('print_jobs'));
    }
}
