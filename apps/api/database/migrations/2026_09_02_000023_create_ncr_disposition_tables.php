<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ncrs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('qc_inspection_id')->constrained('qc_inspections')->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->string('status', 16)->default('DRAFT');
            $table->text('notes')->nullable();
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_ncrs_doc_no');
            $table->unique('qc_inspection_id', 'uq_ncrs_inspection');
            $table->index(['production_order_id', 'status'], 'idx_ncrs_mo_status');
        });

        DB::statement("ALTER TABLE ncrs ADD CONSTRAINT chk_ncrs_status CHECK (status IN ('DRAFT','SUBMITTED','APPROVED','REJECTED','CLOSED','CANCELLED'))");

        Schema::create('dispositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('ncr_id')->constrained('ncrs')->restrictOnDelete();
            $table->string('action', 16);
            $table->decimal('qty', 18, 4);
            $table->string('target_stage', 16)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at', 6)->nullable();
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->index(['ncr_id', 'action'], 'idx_dispositions_ncr_action');
        });

        DB::statement("ALTER TABLE dispositions ADD CONSTRAINT chk_dispositions_action CHECK (action IN ('REWORK','REPAIR','REJECT','SECOND_GRADE','SCRAP'))");
        DB::statement("ALTER TABLE dispositions ADD CONSTRAINT chk_dispositions_target_stage CHECK (target_stage IS NULL OR target_stage IN ('CUTTING','SEWING','FINISHING'))");

        Schema::create('rework_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('ncr_id')->constrained('ncrs')->restrictOnDelete();
            $table->foreignId('disposition_id')->constrained('dispositions')->restrictOnDelete();
            $table->foreignId('bundle_id')->nullable()->constrained('bundles')->restrictOnDelete();
            $table->string('target_stage', 16);
            $table->decimal('qty', 18, 4);
            $table->unsignedInteger('rework_count')->default(1);
            $table->foreignId('reinspection_id')->nullable()->constrained('qc_inspections')->restrictOnDelete();
            $table->string('status', 12)->default('OPEN');
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('disposition_id', 'uq_rework_orders_disposition');
            $table->index(['ncr_id', 'status'], 'idx_rework_orders_ncr_status');
            $table->index(['bundle_id', 'status'], 'idx_rework_orders_bundle_status');
            $table->index('reinspection_id', 'idx_rework_orders_reinspection');
        });

        DB::statement("ALTER TABLE rework_orders ADD CONSTRAINT chk_rework_orders_status CHECK (status IN ('OPEN','CLOSED','CANCELLED'))");
        DB::statement("ALTER TABLE rework_orders ADD CONSTRAINT chk_rework_orders_target_stage CHECK (target_stage IN ('CUTTING','SEWING','FINISHING'))");

        Schema::table('rework_records', function (Blueprint $table) {
            $table->foreignId('rework_order_id')->nullable()->after('company_id')->constrained('rework_orders')->restrictOnDelete();
            $table->index('rework_order_id', 'idx_rework_records_order');
        });
    }

    public function down(): void
    {
        Schema::table('rework_records', function (Blueprint $table) {
            $table->dropForeign(['rework_order_id']);
            $table->dropIndex('idx_rework_records_order');
            $table->dropColumn('rework_order_id');
        });
        Schema::dropIfExists('rework_orders');
        Schema::dropIfExists('dispositions');
        Schema::dropIfExists('ncrs');
    }
};
