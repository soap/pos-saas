<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §4.7 — Audit, Idempotency, Outbox
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('store_id')->nullable();
            $table->uuid('actor_user_id')->nullable();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->useCurrent();

            // ไม่ใส่ FK แบบ cascade เพื่อให้ audit log อยู่ได้แม้ข้อมูลต้นทางเปลี่ยน
            $table->index(['tenant_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id')->nullable();
            $table->uuid('actor_user_id');
            $table->string('operation', 64);
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->smallInteger('response_status')->nullable(); // NULL = กำลังประมวลผล
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // Unique scope: (tenant, actor, operation, key) — ไม่รวม store_id ที่อาจเป็น NULL
            // จึงไม่เจอปัญหา NULL ซ้ำใน UNIQUE ของ PostgreSQL (ดู spec §4.7)
            $table->unique(['tenant_id', 'actor_user_id', 'operation', 'idempotency_key'], 'idempotency_keys_scope_unique');
            $table->index('expires_at');
        });

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('aggregate_type', 64);
            $table->uuid('aggregate_id');
            $table->string('event_type', 64);
            $table->jsonb('payload')->default('{}');
            $table->timestampTz('occurred_at')->useCurrent();
            $table->timestampTz('published_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->index(['published_at', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('audit_logs');
    }
};
