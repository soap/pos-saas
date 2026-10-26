<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec ภาคผนวก B7 — บัญชีรับชำระเงิน QR ต่อ tenant (Omise / EasySlip)
 *
 * - credentials เข้ารหัสด้วย APP_KEY (encrypted cast) — ห้ามตอบกลับใน API
 * - account_key เป็นรหัสสาธารณะสำหรับ route webhook /webhooks/payments/{provider}/{account_key}
 * - สาขาเลือกบัญชี QR ของตัวเองด้วย stores.qr_payment_account_id
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('provider_code', 32);
            $table->string('name');
            $table->string('account_key', 64)->unique();
            $table->text('credentials');
            $table->jsonb('settings')->default('{}');
            $table->string('status', 16)->default('active');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'id'], 'payment_accounts_tenant_id_id_unique');
        });
        DB::statement("ALTER TABLE payment_accounts ADD CONSTRAINT payment_accounts_status_check CHECK (status IN ('active','inactive'))");

        Schema::table('stores', function (Blueprint $table) {
            $table->uuid('qr_payment_account_id')->nullable();
            $table->foreign(['tenant_id', 'qr_payment_account_id'], 'stores_qr_payment_account_fk')
                ->references(['tenant_id', 'id'])->on('payment_accounts')->restrictOnDelete();
        });

        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->uuid('payment_account_id')->nullable();
            // เลขอ้างอิงธุรกรรมจากธนาคาร/provider (เช่น transRef ของสลิป) — กันสลิปเดียวใช้จ่ายซ้ำ
            $table->string('provider_transaction_ref', 128)->nullable();
            $table->foreign(['tenant_id', 'payment_account_id'], 'payment_attempts_account_fk')
                ->references(['tenant_id', 'id'])->on('payment_accounts')->restrictOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX payment_attempts_transaction_ref_unique ON payment_attempts (provider_code, provider_transaction_ref) WHERE provider_transaction_ref IS NOT NULL');

        Schema::table('refunds', function (Blueprint $table) {
            $table->string('method', 24)->default('provider');
        });
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_method_check CHECK (method IN ('cash','provider','manual_transfer'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_method_check');
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropColumn('method');
        });

        DB::statement('DROP INDEX payment_attempts_transaction_ref_unique');
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropForeign('payment_attempts_account_fk');
            $table->dropColumn(['payment_account_id', 'provider_transaction_ref']);
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropForeign('stores_qr_payment_account_fk');
            $table->dropColumn('qr_payment_account_id');
        });

        Schema::dropIfExists('payment_accounts');
    }
};
