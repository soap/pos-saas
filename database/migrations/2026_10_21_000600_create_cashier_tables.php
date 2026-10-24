<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §4.5 — Cashier shift และ Cash movements
 * Policy (ตกลงแล้ว): เปิดได้ 1 กะต่อ store
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('cashier_user_id');
            $table->bigInteger('opening_float_satang');
            $table->bigInteger('expected_cash_satang')->nullable();
            $table->bigInteger('counted_cash_satang')->nullable();
            $table->bigInteger('variance_satang')->nullable();
            $table->string('status', 16)->default('open');
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->text('close_note')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'store_id'], 'cashier_shifts_store_fk')
                ->references(['tenant_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign('cashier_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'store_id', 'id'], 'cashier_shifts_tenant_store_id_unique');
            $table->index(['tenant_id', 'store_id', 'opened_at']);
        });
        DB::statement("CREATE UNIQUE INDEX cashier_shifts_one_open_per_store ON cashier_shifts (store_id) WHERE status = 'open'");
        DB::statement("ALTER TABLE cashier_shifts ADD CONSTRAINT cashier_shifts_status_check CHECK (status IN ('open','closed'))");
        DB::statement('ALTER TABLE cashier_shifts ADD CONSTRAINT cashier_shifts_float_check CHECK (opening_float_satang >= 0 AND (counted_cash_satang IS NULL OR counted_cash_satang >= 0))');

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('shift_id');
            $table->string('movement_type', 16);
            $table->bigInteger('amount_satang'); // ค่าบวกเสมอ ยกเว้น adjustment ที่อาจติดลบได้
            $table->text('reason')->nullable();
            $table->string('reference_type', 32)->nullable();
            $table->uuid('reference_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['tenant_id', 'store_id', 'shift_id'], 'cash_movements_shift_fk')
                ->references(['tenant_id', 'store_id', 'id'])->on('cashier_shifts')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index('shift_id');
        });
        DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_type_check CHECK (movement_type IN ('cash_in','cash_out','cash_sale','refund','adjustment'))");
        DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_amount_check CHECK ((movement_type = 'adjustment' AND amount_satang <> 0) OR (movement_type <> 'adjustment' AND amount_satang > 0))");

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'store_id', 'cashier_shift_id'], 'payments_shift_fk')
                ->references(['tenant_id', 'store_id', 'id'])->on('cashier_shifts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign('payments_shift_fk');
        });
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cashier_shifts');
    }
};
