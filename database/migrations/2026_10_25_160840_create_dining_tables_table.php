<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ทะเบียนโต๊ะต่อสาขา + ผูกออเดอร์ทานที่ร้านกับโต๊ะ
 *
 * - 1 โต๊ะมีออเดอร์ที่ยังเปิดได้ครั้งละ 1 ใบ (partial unique index) — ปิด/ยกเลิกออเดอร์แล้วโต๊ะว่างเอง
 * - ไม่ลบโต๊ะ (ออเดอร์เก่าอ้างถึง) ใช้ is_active=false แทน
 * - orders.table_label ยังเป็น snapshot ชื่อโต๊ะสำหรับครัว/ใบเสร็จ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dining_tables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->string('name', 32);
            $table->string('zone', 64)->nullable();
            $table->smallInteger('seats')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'store_id'], 'dining_tables_store_fk')
                ->references(['tenant_id', 'id'])->on('stores')->restrictOnDelete();
            $table->unique(['tenant_id', 'store_id', 'id'], 'dining_tables_tenant_store_id_unique');
            $table->index(['tenant_id', 'store_id', 'sort_order']);
        });
        // ชื่อโต๊ะซ้ำในสาขาเดียวกันไม่ได้ (ไม่สนตัวพิมพ์เล็ก/ใหญ่)
        DB::statement('CREATE UNIQUE INDEX dining_tables_store_name_unique ON dining_tables (store_id, LOWER(name))');
        DB::statement('ALTER TABLE dining_tables ADD CONSTRAINT dining_tables_seats_check CHECK (seats IS NULL OR seats > 0)');

        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('dining_table_id')->nullable();
            // FK รวม store_id → ผูกโต๊ะของสาขาอื่นไม่ได้แม้อยู่ tenant เดียวกัน
            $table->foreign(['tenant_id', 'store_id', 'dining_table_id'], 'orders_dining_table_fk')
                ->references(['tenant_id', 'store_id', 'id'])->on('dining_tables')->restrictOnDelete();
        });
        DB::statement("CREATE UNIQUE INDEX orders_one_open_per_table ON orders (dining_table_id) WHERE dining_table_id IS NOT NULL AND status IN ('draft','placed','preparing','ready','served')");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_table_dine_in_check CHECK (dining_table_id IS NULL OR order_type = 'dine_in')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_table_dine_in_check');
        DB::statement('DROP INDEX orders_one_open_per_table');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_dining_table_fk');
            $table->dropColumn('dining_table_id');
        });

        Schema::dropIfExists('dining_tables');
    }
};
