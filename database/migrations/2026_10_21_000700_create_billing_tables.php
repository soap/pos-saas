<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §4.6 — SaaS plans และ subscriptions
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->bigInteger('price_satang');
            $table->string('billing_interval', 16)->default('month'); // month, year
            $table->jsonb('limits')->default('{}'); // เช่น {"max_stores":1,"max_users":5}
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_price_check CHECK (price_satang >= 0)');
        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_interval_check CHECK (billing_interval IN ('month','year'))");

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('plan_id');
            $table->string('status', 16);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->timestampTz('trial_ends_at')->nullable();
            $table->string('provider_customer_ref', 128)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('plan_id')->references('id')->on('plans')->restrictOnDelete();
            $table->unique(['tenant_id', 'id'], 'subscriptions_tenant_id_id_unique');
            $table->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('trialing','active','past_due','cancelled'))");
        // tenant หนึ่งมี subscription ที่ยังมีผล (ไม่ cancelled) ได้ครั้งละ 1 รายการ
        DB::statement("CREATE UNIQUE INDEX subscriptions_one_current_per_tenant ON subscriptions (tenant_id) WHERE status <> 'cancelled'");

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('subscription_id');
            $table->bigInteger('amount_satang');
            $table->char('currency', 3)->default('THB');
            $table->string('status', 16)->default('pending');
            $table->timestampTz('billing_period_start');
            $table->timestampTz('billing_period_end');
            $table->bigInteger('plan_price_snapshot_satang');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'subscription_id'], 'subscription_payments_sub_fk')
                ->references(['tenant_id', 'id'])->on('subscriptions')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE subscription_payments ADD CONSTRAINT subscription_payments_amount_check CHECK (amount_satang >= 0 AND plan_price_snapshot_satang >= 0)');

        Schema::create('tenant_features', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->string('feature_key', 64);
            $table->boolean('enabled')->default(true);
            $table->bigInteger('limit_value')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->primary(['tenant_id', 'feature_key']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_features');
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
