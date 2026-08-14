<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // BR-043/045: MRP run — setiap run tersimpan sebagai versi (bisa dibandingkan)
        Schema::create('mrp_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedInteger('run_no');
            $table->json('params')->nullable();          // horizon, time fence, so_ids
            $table->string('status', 16)->default('COMPLETED');
            $table->timestamps(6);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->unique(['company_id', 'run_no'], 'uq_mrp_runs_no');
        });

        DB::statement("ALTER TABLE mrp_runs ADD CONSTRAINT chk_mrp_runs_status CHECK (status IN ('COMPLETED','FAILED'))");

        // Hasil netting per material per run — BR-045: suggestion saja, BUKAN auto-PO
        Schema::create('mrp_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mrp_run_id')->constrained('mrp_runs')->restrictOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->decimal('gross_qty', 18, 4);         // dari BOM explode
            $table->decimal('safety_stock_qty', 18, 4)->default(0);
            $table->decimal('available_qty', 18, 4);     // on_hand − reserved − quality_hold
            $table->decimal('on_order_qty', 18, 4);      // PO approved belum diterima
            $table->decimal('net_qty', 18, 4);           // max(0, gross + safety − available − on_order)
            $table->foreignId('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->date('need_date')->nullable();
            $table->boolean('converted_to_pr')->default(false);
            $table->timestamps(6);

            $table->index(['mrp_run_id', 'material_id'], 'idx_mrp_requirements_run');
        });

        // FK closure: pr_lines.mrp_requirement_id → mrp_requirements (BR-120)
        Schema::table('pr_lines', function (Blueprint $table) {
            $table->foreign('mrp_requirement_id', 'fk_pr_lines_mrp')
                ->references('id')->on('mrp_requirements')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pr_lines', fn (Blueprint $t) => $t->dropForeign('fk_pr_lines_mrp'));
        Schema::dropIfExists('mrp_requirements');
        Schema::dropIfExists('mrp_runs');
    }
};
