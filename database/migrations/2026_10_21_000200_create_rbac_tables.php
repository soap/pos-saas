<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §4.1 — roles, permissions, role_permissions, membership_roles
 *
 * Global/system role = tenant_id IS NULL (เช่น owner, manager, cashier, kitchen)
 * Tenant custom role = tenant_id NOT NULL
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('name');
            $table->string('slug', 64);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
        });
        // UNIQUE(tenant_id, slug) ที่ถูกต้องเมื่อ tenant_id เป็น NULL → ใช้ partial unique index 2 ตัว
        DB::statement('CREATE UNIQUE INDEX roles_system_slug_unique ON roles (slug) WHERE tenant_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX roles_tenant_slug_unique ON roles (tenant_id, slug) WHERE tenant_id IS NOT NULL');

        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 64)->unique();
            $table->string('description')->nullable();
            $table->timestampsTz();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->uuid('role_id');
            $table->uuid('permission_id');
            $table->primary(['role_id', 'permission_id']);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        });

        Schema::create('membership_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('membership_id');
            $table->uuid('role_id');
            $table->uuid('store_id')->nullable(); // NULL = ใช้ได้ทุก store ของ tenant
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'membership_id'], 'membership_roles_membership_fk')
                ->references(['tenant_id', 'id'])->on('tenant_memberships')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'store_id'], 'membership_roles_store_fk')
                ->references(['tenant_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
            $table->index(['tenant_id', 'membership_id']);
        });
        DB::statement('CREATE UNIQUE INDEX membership_roles_tenantwide_unique ON membership_roles (membership_id, role_id) WHERE store_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX membership_roles_store_unique ON membership_roles (membership_id, role_id, store_id) WHERE store_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
