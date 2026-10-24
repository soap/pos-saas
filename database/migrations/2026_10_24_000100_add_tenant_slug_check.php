<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * slug ต้องใช้เป็น subdomain/path ได้ (ตรงกับ Tenant::SLUG_PATTERN)
 * NOT VALID = บังคับกับแถวใหม่/แถวที่แก้ไข ไม่ล้มถ้ามี slug เก่าที่ผิดรูปแบบ (แก้แล้วค่อย VALIDATE CONSTRAINT)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_slug_format_check CHECK (slug ~ '^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$') NOT VALID");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_slug_format_check');
    }
};
