<?php

use App\Modules\Identity\Services\RbacService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * รอบชำระค่าแพ็กเกจ (README §7) — ร้านจ่ายผ่าน PromptPay ของบัญชี Omise แพลตฟอร์ม
 *
 * subscriptions.current_period_end = จ่ายแล้วถึงเมื่อไร (trial ใช้ trial_ends_at); NULL = ไม่มีรอบเก็บเงิน (แพ็กเกจฟรี/admin ให้ใช้)
 * subscription_payments = ใบแจ้งหนี้ต่อรอบ + charge PromptPay ล่าสุดของใบนั้น
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestampTz('current_period_end')->nullable()->after('trial_ends_at');
        });

        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->string('provider_charge_ref', 64)->nullable()->after('status');
            $table->text('qr_image_url')->nullable()->after('provider_charge_ref');
            $table->timestampTz('charge_expires_at')->nullable()->after('qr_image_url');

            $table->unique('provider_charge_ref');
            // ใบแจ้งหนี้ของรอบเดียวกันมีได้ใบเดียว — billing:cycle รันซ้ำ/พร้อมกันได้
            $table->unique(['subscription_id', 'billing_period_start'], 'subscription_payments_one_per_period');
            $table->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE subscription_payments ADD CONSTRAINT subscription_payments_status_check CHECK (status IN ('pending','paid','void'))");

        // permission ใหม่ billing.manage
        app(RbacService::class)->syncSystemRoles();
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE subscription_payments DROP CONSTRAINT IF EXISTS subscription_payments_status_check');
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropUnique('subscription_payments_one_per_period');
            $table->dropUnique(['provider_charge_ref']);
            $table->dropColumn(['provider_charge_ref', 'qr_image_url', 'charge_expires_at']);
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('current_period_end');
        });
    }
};
