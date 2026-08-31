<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopfloor_devices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
            $table->string('name', 100);
            $table->string('platform', 50)->nullable();
            $table->string('status', 16)->default('ACTIVE');
            $table->timestamp('last_seen_at', 6)->nullable();
            $table->timestamp('revoked_at', 6)->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamps(6);
            $table->unique('public_id', 'uq_shopfloor_devices_public');
            $table->unique('token_id', 'uq_shopfloor_devices_token');
            $table->index(['company_id', 'status'], 'idx_shopfloor_devices_company_status');
        });
        DB::statement("ALTER TABLE shopfloor_devices ADD CONSTRAINT chk_shopfloor_devices_status CHECK (status IN ('ACTIVE','REVOKED'))");

        Schema::table('bundles', function (Blueprint $table): void {
            $table->unsignedBigInteger('scan_version')->default(0)->after('status');
        });
        DB::statement('UPDATE bundles b SET b.scan_version = (SELECT COUNT(*) FROM production_scans ps WHERE ps.bundle_id = b.id)');

        Schema::table('production_scans', function (Blueprint $table): void {
            $table->foreignId('device_id')->nullable()->after('employee_id')->constrained('shopfloor_devices')->restrictOnDelete();
            $table->string('client_event_id', 64)->nullable()->after('device_id');
            $table->unsignedBigInteger('bundle_version')->nullable()->after('client_event_id');
            $table->timestamp('client_scanned_at', 6)->nullable()->after('scanned_at');
            $table->timestamp('received_at', 6)->nullable()->after('client_scanned_at');
            $table->char('payload_hash', 64)->nullable()->after('received_at');
            $table->unique(['device_id', 'client_event_id'], 'uq_scans_device_event');
            $table->index(['device_id', 'received_at'], 'idx_scans_device_received');
        });
    }

    public function down(): void
    {
        Schema::table('production_scans', function (Blueprint $table): void {
            $table->dropUnique('uq_scans_device_event');
            $table->dropIndex('idx_scans_device_received');
            $table->dropForeign(['device_id']);
            $table->dropColumn(['device_id','client_event_id','bundle_version','client_scanned_at','received_at','payload_hash']);
        });
        Schema::table('bundles', fn (Blueprint $table) => $table->dropColumn('scan_version'));
        Schema::dropIfExists('shopfloor_devices');
    }
};
