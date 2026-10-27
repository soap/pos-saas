<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * รูปที่ใช้แสดงของสินค้า (เลือกจากรูปใน collection images) — null = ใช้รูปแรกตามลำดับ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('primary_media_id')->nullable()->after('sort_order')->constrained('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('primary_media_id');
        });
    }
};
