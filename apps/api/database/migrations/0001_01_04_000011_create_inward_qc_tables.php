<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Inward QC (FQC): 4-point, shrinkage, GSM, shade — per PF-03
        Schema::create('inward_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->restrictOnDelete();
            $table->foreignId('inspector_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->string('result', 8)->default('PENDING');
            $table->text('notes')->nullable();
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_inward_inspections_doc_no');
        });

        DB::statement("ALTER TABLE inward_inspections ADD CONSTRAINT chk_inward_result CHECK (result IN ('PENDING','PASS','FAIL','PARTIAL'))");

        Schema::create('inward_inspection_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inward_inspection_id')->constrained('inward_inspections')->restrictOnDelete();
            $table->foreignId('gr_line_id')->constrained('gr_lines')->restrictOnDelete();
            $table->foreignId('roll_id')->nullable()->constrained('fabric_rolls')->restrictOnDelete();
            // 4-point system: points per 100 square yards
            $table->decimal('four_point_points', 10, 2)->nullable();
            $table->decimal('shrinkage_pct_actual', 7, 4)->nullable();
            $table->decimal('gsm_actual', 10, 2)->nullable();
            $table->string('shade_verdict', 16)->nullable();   // MATCH/DEVIATION
            $table->foreignId('defect_id')->nullable()->constrained('defect_library')->restrictOnDelete(); // BR-072
            $table->string('result', 8);                        // PASS/FAIL
            $table->text('notes')->nullable();
            $table->timestamps(6);

            $table->index('inward_inspection_id', 'idx_inward_lines_insp');
            $table->index('roll_id', 'idx_inward_lines_roll');
        });

        DB::statement("ALTER TABLE inward_inspection_lines ADD CONSTRAINT chk_inward_lines_result CHECK (result IN ('PASS','FAIL'))");

        // BR-004: FAIL → return ke supplier + claim
        Schema::create('supplier_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->text('reason');
            $table->decimal('claim_amount', 19, 4)->nullable();
            $table->string('status', 16)->default('DRAFT');
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_supplier_returns_doc_no');
            $table->index('goods_receipt_id', 'idx_supplier_returns_gr');
        });

        DB::statement("ALTER TABLE supplier_returns ADD CONSTRAINT chk_supplier_returns_status CHECK (status IN ('DRAFT','SUBMITTED','APPROVED','SHIPPED','CLOSED','CANCELLED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_returns');
        Schema::dropIfExists('inward_inspection_lines');
        Schema::dropIfExists('inward_inspections');
    }
};
