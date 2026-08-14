<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->string('source', 8)->default('MANUAL');   // MRP (Phase 5) / MANUAL
            $table->date('needed_by')->nullable();
            $table->string('status', 16)->default('DRAFT');
            $table->text('notes')->nullable();
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_pr_doc_no');
        });

        DB::statement("ALTER TABLE purchase_requests ADD CONSTRAINT chk_pr_status CHECK (status IN ('DRAFT','SUBMITTED','APPROVED','REJECTED','CANCELLED','CONVERTED'))");
        DB::statement("ALTER TABLE purchase_requests ADD CONSTRAINT chk_pr_source CHECK (source IN ('MRP','MANUAL'))");

        Schema::create('pr_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained('purchase_requests')->restrictOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->foreignId('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->date('need_date')->nullable();
            $table->unsignedBigInteger('mrp_requirement_id')->nullable(); // → mrp_requirements (Phase 5; BR-120)
            $table->timestamps(6);

            $table->index('purchase_request_id', 'idx_pr_lines');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pr_lines');
        Schema::dropIfExists('purchase_requests');
    }
};
