<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §4.4 — Payments, Attempts, Webhooks, Refunds
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('order_id');
            $table->uuid('cashier_shift_id')->nullable();
            $table->string('method', 16); // cash, qr
            $table->bigInteger('amount_satang');
            $table->bigInteger('tendered_satang')->nullable(); // เงินที่ลูกค้าให้ (cash)
            $table->bigInteger('change_satang')->nullable();
            $table->char('currency', 3)->default('THB');
            $table->string('status', 24)->default('pending');
            $table->bigInteger('refunded_satang')->default(0); // ผลรวม refund ที่สำเร็จ (denormalized, อัปเดตภายใต้ lock)
            $table->timestampTz('paid_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'store_id', 'order_id'], 'payments_order_fk')
                ->references(['tenant_id', 'store_id', 'id'])->on('orders')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'store_id', 'id'], 'payments_tenant_store_id_unique');
            $table->index(['tenant_id', 'store_id', 'status']);
            $table->index('order_id');
        });
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount_satang > 0 AND refunded_satang >= 0 AND refunded_satang <= amount_satang)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ('cash','qr'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending','processing','succeeded','failed','cancelled','partially_refunded','refunded'))");

        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('payment_id');
            $table->integer('attempt_number');
            $table->string('provider_code', 32);
            $table->string('provider_reference', 128)->nullable();
            $table->string('status', 24)->default('pending');
            $table->jsonb('request_payload')->default('{}');
            $table->jsonb('response_payload')->default('{}');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'store_id', 'payment_id'], 'payment_attempts_payment_fk')
                ->references(['tenant_id', 'store_id', 'id'])->on('payments')->restrictOnDelete();
            $table->unique(['payment_id', 'attempt_number']);
        });
        DB::statement('CREATE UNIQUE INDEX payment_attempts_provider_ref_unique ON payment_attempts (provider_code, provider_reference) WHERE provider_reference IS NOT NULL');

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider_code', 32);
            $table->string('provider_account_key', 64)->default(''); // '' แทน NULL เพื่อให้ unique ทำงาน
            $table->string('external_event_id', 128);
            $table->jsonb('payload');
            $table->timestampTz('signature_verified_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->string('processing_status', 16)->default('received'); // received, processed, ignored, failed
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            $table->unique(['provider_code', 'provider_account_key', 'external_event_id'], 'payment_webhook_events_dedupe_unique');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('payment_id');
            $table->bigInteger('amount_satang');
            $table->string('status', 16)->default('pending');
            $table->text('reason');
            $table->string('provider_reference', 128)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('succeeded_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'store_id', 'payment_id'], 'refunds_payment_fk')
                ->references(['tenant_id', 'store_id', 'id'])->on('payments')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index('payment_id');
        });
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_check CHECK (amount_satang > 0)');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status_check CHECK (status IN ('pending','succeeded','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payments');
    }
};
