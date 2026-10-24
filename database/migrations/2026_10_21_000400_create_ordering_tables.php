<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §4.3 — Orders และ Kitchen
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_order_counters', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->date('business_date');
            $table->bigInteger('next_number')->default(1);
            $table->timestampsTz();

            $table->primary(['store_id', 'business_date']);
            $table->foreign(['tenant_id', 'store_id'], 'store_order_counters_store_fk')
                ->references(['tenant_id', 'id'])->on('stores')->restrictOnDelete();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->date('business_date');
            $table->bigInteger('order_number');
            $table->string('order_type', 24); // dine_in, takeaway
            $table->string('table_label', 32)->nullable();
            $table->string('status', 24)->default('draft');
            $table->bigInteger('subtotal_satang')->default(0);
            $table->bigInteger('discount_satang')->default(0);
            $table->bigInteger('tax_satang')->default(0);
            $table->bigInteger('total_satang')->default(0);
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'store_id'], 'orders_tenant_store_fk')
                ->references(['tenant_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['store_id', 'business_date', 'order_number']);
            $table->unique(['tenant_id', 'store_id', 'id'], 'orders_tenant_store_id_unique');
            $table->index(['tenant_id', 'store_id', 'business_date', 'status']);
        });
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_totals_check CHECK (subtotal_satang >= 0 AND discount_satang >= 0 AND tax_satang >= 0 AND total_satang >= 0 AND discount_satang <= subtotal_satang)');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_type_check CHECK (order_type IN ('dine_in','takeaway'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('draft','placed','preparing','ready','served','completed','cancelled'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_number_check CHECK (order_number > 0)');

        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('order_id');
            $table->uuid('product_id')->nullable();
            $table->string('product_name_snapshot');
            $table->string('sku_snapshot', 64)->nullable();
            $table->bigInteger('unit_price_satang');
            $table->bigInteger('modifiers_total_satang')->default(0); // ผลรวม price_delta ต่อ 1 หน่วย
            $table->integer('quantity');
            $table->bigInteger('discount_satang')->default(0);
            $table->bigInteger('tax_satang')->default(0);
            $table->bigInteger('line_total_satang');
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('active'); // active, voided
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'store_id', 'order_id'], 'order_items_order_fk')
                ->references(['tenant_id', 'store_id', 'id'])->on('orders')->restrictOnDelete();
            $table->foreign(['tenant_id', 'product_id'], 'order_items_product_fk')
                ->references(['tenant_id', 'id'])->on('products')->restrictOnDelete();
            $table->unique(['tenant_id', 'id'], 'order_items_tenant_id_id_unique');
            $table->index('order_id');
        });
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_qty_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_amount_check CHECK (unit_price_satang >= 0 AND discount_satang >= 0 AND tax_satang >= 0 AND line_total_satang >= 0)');
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_status_check CHECK (status IN ('active','voided'))");

        Schema::create('order_item_modifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('order_item_id');
            $table->uuid('modifier_option_id')->nullable();
            $table->string('group_name_snapshot')->nullable();
            $table->string('name_snapshot');
            $table->bigInteger('price_delta_satang')->default(0);
            $table->integer('quantity')->default(1);
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'order_item_id'], 'oim_order_item_fk')
                ->references(['tenant_id', 'id'])->on('order_items')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'modifier_option_id'], 'oim_option_fk')
                ->references(['tenant_id', 'id'])->on('modifier_options')->restrictOnDelete();
            $table->index('order_item_id');
        });
        DB::statement('ALTER TABLE order_item_modifiers ADD CONSTRAINT oim_qty_check CHECK (quantity > 0)');

        Schema::create('order_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('order_id');
            $table->string('event_type', 48);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->uuid('actor_id')->nullable();
            $table->jsonb('payload')->default('{}');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['tenant_id', 'store_id', 'order_id'], 'order_events_order_fk')
                ->references(['tenant_id', 'store_id', 'id'])->on('orders')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['order_id', 'created_at']);
        });

        // ---- Kitchen ----
        Schema::create('kitchen_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('order_id');
            $table->integer('revision');
            $table->string('status', 16)->default('queued');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('served_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'store_id', 'order_id'], 'kitchen_tickets_order_fk')
                ->references(['tenant_id', 'store_id', 'id'])->on('orders')->restrictOnDelete();
            $table->unique(['order_id', 'revision']);
            $table->unique(['tenant_id', 'store_id', 'id'], 'kitchen_tickets_tenant_store_id_unique');
            $table->index(['tenant_id', 'store_id', 'status', 'updated_at']);
        });
        DB::statement("ALTER TABLE kitchen_tickets ADD CONSTRAINT kitchen_tickets_status_check CHECK (status IN ('queued','preparing','ready','served','cancelled'))");
        DB::statement('ALTER TABLE kitchen_tickets ADD CONSTRAINT kitchen_tickets_revision_check CHECK (revision > 0)');

        Schema::create('kitchen_ticket_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('kitchen_ticket_id');
            $table->uuid('order_item_id');
            $table->string('name_snapshot');
            $table->integer('quantity');
            $table->jsonb('modifiers_snapshot')->default('[]');
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'store_id', 'kitchen_ticket_id'], 'kti_ticket_fk')
                ->references(['tenant_id', 'store_id', 'id'])->on('kitchen_tickets')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'order_item_id'], 'kti_order_item_fk')
                ->references(['tenant_id', 'id'])->on('order_items')->restrictOnDelete();
            $table->index('kitchen_ticket_id');
            $table->index('order_item_id');
        });
        DB::statement('ALTER TABLE kitchen_ticket_items ADD CONSTRAINT kti_qty_check CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_ticket_items');
        Schema::dropIfExists('kitchen_tickets');
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('order_item_modifiers');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('store_order_counters');
    }
};
