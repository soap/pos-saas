<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ภาคผนวก D5 — แยก kitchen ticket ตาม station
 *
 * - station ระดับ tenant (เช่น เครื่องดื่ม/ย่าง/เส้น); สินค้าเลือก station ได้ (null = ครัวหลัก)
 * - ส่งครัว 1 ครั้ง = revision เดียว แต่แตกเป็น ticket ละ station → unique (order, revision, station)
 * - ticket เก็บ station ตอนส่ง ย้าย station ของสินค้าภายหลังไม่กระทบ ticket เดิม
 */
return new class extends Migration
{
    private const NULL_STATION = '00000000-0000-0000-0000-000000000000';

    public function up(): void
    {
        Schema::create('kitchen_stations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 64);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'id'], 'kitchen_stations_tenant_id_id_unique');
        });
        DB::statement('CREATE UNIQUE INDEX kitchen_stations_tenant_name_unique ON kitchen_stations (tenant_id, LOWER(name))');

        Schema::table('products', function (Blueprint $table) {
            $table->uuid('kitchen_station_id')->nullable();
            $table->foreign(['tenant_id', 'kitchen_station_id'], 'products_kitchen_station_fk')
                ->references(['tenant_id', 'id'])->on('kitchen_stations')->restrictOnDelete();
        });

        Schema::table('kitchen_tickets', function (Blueprint $table) {
            $table->uuid('kitchen_station_id')->nullable();
            $table->foreign(['tenant_id', 'kitchen_station_id'], 'kitchen_tickets_station_fk')
                ->references(['tenant_id', 'id'])->on('kitchen_stations')->restrictOnDelete();
            $table->dropUnique(['order_id', 'revision']);
        });
        DB::statement("CREATE UNIQUE INDEX kitchen_tickets_order_revision_station_unique ON kitchen_tickets (order_id, revision, COALESCE(kitchen_station_id, '".self::NULL_STATION."'::uuid))");
        DB::statement('CREATE INDEX kitchen_tickets_station_index ON kitchen_tickets (tenant_id, store_id, kitchen_station_id, updated_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX kitchen_tickets_station_index');
        DB::statement('DROP INDEX kitchen_tickets_order_revision_station_unique');
        Schema::table('kitchen_tickets', function (Blueprint $table) {
            $table->dropForeign('kitchen_tickets_station_fk');
            $table->dropColumn('kitchen_station_id');
            $table->unique(['order_id', 'revision']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign('products_kitchen_station_fk');
            $table->dropColumn('kitchen_station_id');
        });

        Schema::dropIfExists('kitchen_stations');
    }
};
