<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PF-06/07: scan IN/OUT bundle per operasi — BR-062 (kehadiran fisik), BR-063 (WIP)
        Schema::create('production_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('bundle_id')->constrained('bundles')->restrictOnDelete();
            $table->foreignId('operation_id')->constrained('operations')->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('line_id')->nullable()->constrained('lines')->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->string('direction', 4);   // IN / OUT
            $table->string('stage', 12);      // SEWING / FINISHING
            $table->timestamp('scanned_at', 6);
            $table->timestamps(6);

            $table->index(['bundle_id', 'operation_id', 'scanned_at'], 'idx_scans_bundle_op');
            $table->index(['production_order_id', 'stage', 'scanned_at'], 'idx_scans_mo_stage');
            $table->index(['line_id', 'scanned_at'], 'idx_scans_line_date');
        });

        DB::statement("ALTER TABLE production_scans ADD CONSTRAINT chk_scans_direction CHECK (direction IN ('IN','OUT'))");
        DB::statement("ALTER TABLE production_scans ADD CONSTRAINT chk_scans_stage CHECK (stage IN ('SEWING','FINISHING'))");

        // Rework/defect inline — defect dari library (BR-072)
        Schema::create('rework_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('bundle_id')->constrained('bundles')->restrictOnDelete();
            $table->foreignId('operation_id')->nullable()->constrained('operations')->restrictOnDelete();
            $table->foreignId('defect_id')->constrained('defect_library')->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->text('notes')->nullable();
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();

            $table->index('bundle_id', 'idx_rework_bundle');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rework_records');
        Schema::dropIfExists('production_scans');
    }
};
