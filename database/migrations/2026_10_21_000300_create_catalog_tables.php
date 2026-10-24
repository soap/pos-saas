<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §4.2 — Catalog และ Modifiers
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'id'], 'categories_tenant_id_id_unique');
            $table->index(['tenant_id', 'sort_order']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('category_id')->nullable();
            $table->string('sku', 64)->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->bigInteger('base_price_satang');
            $table->string('status', 16)->default('active');
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'category_id'], 'products_category_fk')
                ->references(['tenant_id', 'id'])->on('categories')->restrictOnDelete();
            $table->unique(['tenant_id', 'id'], 'products_tenant_id_id_unique');
            $table->index(['tenant_id', 'category_id']);
        });
        DB::statement('CREATE UNIQUE INDEX products_tenant_sku_unique ON products (tenant_id, sku) WHERE sku IS NOT NULL');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_base_price_check CHECK (base_price_satang >= 0)');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('active','inactive'))");

        Schema::create('store_products', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('product_id');
            $table->bigInteger('price_satang');
            $table->boolean('is_available')->default(true);
            $table->string('tax_profile', 32)->default('none');
            $table->timestampsTz();

            $table->primary(['tenant_id', 'store_id', 'product_id']);
            $table->foreign(['tenant_id', 'store_id'], 'store_products_store_fk')
                ->references(['tenant_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['tenant_id', 'product_id'], 'store_products_product_fk')
                ->references(['tenant_id', 'id'])->on('products')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE store_products ADD CONSTRAINT store_products_price_check CHECK (price_satang >= 0)');

        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->integer('min_select')->default(0);
            $table->integer('max_select')->default(1);
            $table->boolean('is_required')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'id'], 'modifier_groups_tenant_id_id_unique');
        });
        DB::statement('ALTER TABLE modifier_groups ADD CONSTRAINT modifier_groups_select_check CHECK (min_select >= 0 AND max_select >= min_select AND max_select >= 1)');

        Schema::create('modifier_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('modifier_group_id');
            $table->string('name');
            $table->bigInteger('price_delta_satang')->default(0); // อนุญาตค่าติดลบ (เช่น ไม่ใส่ไข่ -5 บาท)
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'modifier_group_id'], 'modifier_options_group_fk')
                ->references(['tenant_id', 'id'])->on('modifier_groups')->restrictOnDelete();
            $table->unique(['tenant_id', 'id'], 'modifier_options_tenant_id_id_unique');
            $table->index('modifier_group_id');
        });

        Schema::create('product_modifier_groups', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->uuid('product_id');
            $table->uuid('modifier_group_id');
            $table->integer('sort_order')->default(0);

            $table->primary(['tenant_id', 'product_id', 'modifier_group_id']);
            $table->foreign(['tenant_id', 'product_id'], 'pmg_product_fk')
                ->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'modifier_group_id'], 'pmg_group_fk')
                ->references(['tenant_id', 'id'])->on('modifier_groups')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_modifier_groups');
        Schema::dropIfExists('modifier_options');
        Schema::dropIfExists('modifier_groups');
        Schema::dropIfExists('store_products');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
