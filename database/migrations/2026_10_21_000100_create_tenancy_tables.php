<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §4.1 — Tenant, Store, Membership
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug', 64)->unique();
            $table->string('status', 16)->default('active');
            $table->string('timezone', 64)->default('Asia/Bangkok');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('active','suspended','closed'))");

        Schema::create('stores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 32);
            $table->string('name');
            $table->string('timezone', 64)->default('Asia/Bangkok');
            $table->string('status', 16)->default('active');
            $table->jsonb('settings')->default('{}');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id'], 'stores_tenant_id_id_unique');
        });
        DB::statement("ALTER TABLE stores ADD CONSTRAINT stores_status_check CHECK (status IN ('active','inactive'))");

        Schema::create('tenant_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->string('status', 16)->default('active');
            $table->timestampTz('joined_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'user_id']);
            $table->unique(['tenant_id', 'id'], 'tenant_memberships_tenant_id_id_unique');
            $table->index('user_id');
        });
        DB::statement("ALTER TABLE tenant_memberships ADD CONSTRAINT tenant_memberships_status_check CHECK (status IN ('active','suspended'))");

        Schema::create('store_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('user_id');
            $table->string('status', 16)->default('active');
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'store_id'], 'store_memberships_store_fk')
                ->references(['tenant_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['tenant_id', 'user_id'], 'store_memberships_tenant_membership_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_memberships')->restrictOnDelete();
            $table->unique(['store_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });
        DB::statement("ALTER TABLE store_memberships ADD CONSTRAINT store_memberships_status_check CHECK (status IN ('active','suspended'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('store_memberships');
        Schema::dropIfExists('tenant_memberships');
        Schema::dropIfExists('stores');
        Schema::dropIfExists('tenants');
    }
};
