<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec ภาคผนวก C5 — store_devices, print_jobs, device_events
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->string('device_code', 32);
            $table->string('name');
            $table->string('device_type', 24); // pos_terminal, receipt_printer, kitchen_printer, kitchen_display, cash_drawer
            $table->string('connection_type', 24); // browser, network_escpos, bridge, vendor_cloud
            $table->string('status', 16)->default('active');
            $table->timestampTz('last_seen_at')->nullable();
            $table->jsonb('config')->default('{}'); // ห้ามเก็บ secret แบบ plaintext
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'store_id'], 'store_devices_store_fk')
                ->references(['tenant_id', 'id'])->on('stores')->restrictOnDelete();
            $table->unique(['store_id', 'device_code']);
            $table->unique(['tenant_id', 'store_id', 'id'], 'store_devices_tenant_store_id_unique');
        });
        DB::statement("ALTER TABLE store_devices ADD CONSTRAINT store_devices_type_check CHECK (device_type IN ('pos_terminal','receipt_printer','kitchen_printer','kitchen_display','cash_drawer','qr_display','barcode_scanner'))");
        DB::statement("ALTER TABLE store_devices ADD CONSTRAINT store_devices_status_check CHECK (status IN ('active','inactive'))");

        Schema::create('print_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->string('document_type', 24); // receipt, kitchen_ticket
            $table->string('source_type', 32);
            $table->uuid('source_id');
            $table->uuid('printer_device_id')->nullable();
            $table->string('status', 16)->default('pending');
            $table->boolean('is_reprint')->default(false);
            $table->integer('attempts')->default(0);
            $table->string('idempotency_key', 128);
            $table->jsonb('payload')->default('{}');
            $table->uuid('requested_by')->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('printed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'store_id'], 'print_jobs_store_fk')
                ->references(['tenant_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['tenant_id', 'store_id', 'printer_device_id'], 'print_jobs_device_fk')
                ->references(['tenant_id', 'store_id', 'id'])->on('store_devices')->restrictOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['store_id', 'idempotency_key']);
            $table->index(['tenant_id', 'store_id', 'status']);
        });
        DB::statement("ALTER TABLE print_jobs ADD CONSTRAINT print_jobs_status_check CHECK (status IN ('pending','printing','printed','failed','cancelled'))");
        DB::statement("ALTER TABLE print_jobs ADD CONSTRAINT print_jobs_doc_check CHECK (document_type IN ('receipt','kitchen_ticket'))");

        Schema::create('device_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('device_id');
            $table->string('event_type', 32);
            $table->jsonb('payload')->default('{}');
            $table->timestampTz('occurred_at')->useCurrent();

            $table->foreign(['tenant_id', 'store_id', 'device_id'], 'device_events_device_fk')
                ->references(['tenant_id', 'store_id', 'id'])->on('store_devices')->restrictOnDelete();
            $table->index(['device_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_events');
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('store_devices');
    }
};
