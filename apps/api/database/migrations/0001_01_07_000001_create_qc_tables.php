<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // BR-070: tahapan QC inline/endline/final; BR-071: final = sampling AQL per buyer (BR-008)
        Schema::create('qc_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->string('stage', 12);                       // INLINE/ENDLINE/FINAL
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            // AQL snapshot dari customer_aql_configs saat inspeksi dibuat (BR-008)
            $table->string('inspection_level', 8)->default('G2');
            $table->decimal('aql_major', 5, 2)->default(2.5);
            $table->decimal('aql_minor', 5, 2)->default(4.0);
            $table->decimal('aql_critical', 5, 2)->default(0);
            // Sampling (BR-071)
            $table->decimal('lot_qty', 18, 4);
            $table->unsignedInteger('sample_size')->nullable();
            $table->unsignedInteger('accept_major')->nullable();   // Ac
            $table->unsignedInteger('reject_major')->nullable();   // Re
            $table->unsignedInteger('defects_major')->default(0);
            $table->unsignedInteger('defects_minor')->default(0);
            $table->unsignedInteger('defects_critical')->default(0);
            $table->unsignedInteger('cycle')->default(1);          // BR-073: rework loop counter
            $table->string('verdict', 12)->default('PENDING');
            $table->text('notes')->nullable();
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_qc_inspections_doc_no');
            $table->index(['production_order_id', 'stage'], 'idx_qc_mo_stage');
        });

        DB::statement("ALTER TABLE qc_inspections ADD CONSTRAINT chk_qc_stage CHECK (stage IN ('INLINE','ENDLINE','FINAL'))");
        DB::statement("ALTER TABLE qc_inspections ADD CONSTRAINT chk_qc_verdict CHECK (verdict IN ('PENDING','PASS','FAIL','REWORK'))");

        // Defect detail per inspeksi — dari library (BR-072)
        Schema::create('qc_inspection_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qc_inspection_id')->constrained('qc_inspections')->restrictOnDelete();
            $table->foreignId('bundle_id')->nullable()->constrained('bundles')->restrictOnDelete();
            $table->foreignId('operation_id')->nullable()->constrained('operations')->restrictOnDelete();
            $table->foreignId('defect_id')->constrained('defect_library')->restrictOnDelete();
            $table->string('severity', 8);   // snapshot dari library: CRITICAL/MAJOR/MINOR
            $table->unsignedInteger('qty')->default(1);
            $table->string('photo_path')->nullable();   // S3 evidence
            $table->text('notes')->nullable();
            $table->timestamps(6);

            $table->index('qc_inspection_id', 'idx_qc_lines_insp');
        });

        DB::statement("ALTER TABLE qc_inspection_lines ADD CONSTRAINT chk_qc_lines_severity CHECK (severity IN ('CRITICAL','MAJOR','MINOR'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_inspection_lines');
        Schema::dropIfExists('qc_inspections');
    }
};
