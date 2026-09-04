<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mrp_runs', function (Blueprint $table) {
            $table->string('run_type', 24)->default('FULL')->after('run_no');
            $table->foreignId('source_amendment_id')->nullable()->after('run_type')->constrained('order_amendments')->restrictOnDelete();
            $table->foreignId('baseline_mrp_run_id')->nullable()->after('source_amendment_id')->constrained('mrp_runs')->restrictOnDelete();
            $table->index(['company_id', 'run_type'], 'idx_mrp_runs_type');
        });
        DB::statement("ALTER TABLE mrp_runs ADD CONSTRAINT chk_mrp_runs_type CHECK (run_type IN ('FULL','AMENDMENT_BASELINE','AMENDMENT_DELTA'))");

        Schema::table('mrp_requirements', function (Blueprint $table) {
            $table->decimal('baseline_gross_qty', 18, 4)->nullable()->after('gross_qty');
            $table->decimal('gross_delta_qty', 18, 4)->nullable()->after('baseline_gross_qty');
            $table->decimal('baseline_net_qty', 18, 4)->nullable()->after('net_qty');
            $table->decimal('net_delta_qty', 18, 4)->nullable()->after('baseline_net_qty');
        });

        Schema::create('order_amendment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('order_amendment_id')->constrained('order_amendments')->restrictOnDelete();
            $table->foreignId('sales_order_line_id')->constrained('sales_order_lines')->restrictOnDelete();
            $table->decimal('old_qty', 18, 4);
            $table->decimal('new_qty', 18, 4);
            $table->decimal('qty_delta', 18, 4);
            $table->timestamps(6);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unique(['order_amendment_id', 'sales_order_line_id'], 'uq_amendment_lines_source');
            $table->index('sales_order_line_id', 'idx_amendment_lines_so_line');
        });
        DB::statement('ALTER TABLE order_amendment_lines ADD CONSTRAINT chk_amendment_lines_old_qty CHECK (old_qty > 0)');
        DB::statement('ALTER TABLE order_amendment_lines ADD CONSTRAINT chk_amendment_lines_new_qty CHECK (new_qty > 0)');

        Schema::table('order_amendments', function (Blueprint $table) {
            $table->date('old_ex_factory_date')->nullable()->after('reason');
            $table->date('new_ex_factory_date')->nullable()->after('old_ex_factory_date');
            $table->foreignId('baseline_mrp_run_id')->nullable()->after('status')->constrained('mrp_runs')->restrictOnDelete();
            $table->foreignId('delta_mrp_run_id')->nullable()->after('baseline_mrp_run_id')->constrained('mrp_runs')->restrictOnDelete();
            $table->timestamp('applied_at', 6)->nullable()->after('delta_mrp_run_id');
            $table->foreignId('applied_by')->nullable()->after('applied_at')->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_amendments', function (Blueprint $table) {
            $table->dropForeign(['baseline_mrp_run_id']);
            $table->dropForeign(['delta_mrp_run_id']);
            $table->dropForeign(['applied_by']);
            $table->dropColumn(['old_ex_factory_date', 'new_ex_factory_date', 'baseline_mrp_run_id', 'delta_mrp_run_id', 'applied_at', 'applied_by']);
        });
        Schema::dropIfExists('order_amendment_lines');
        Schema::table('mrp_requirements', function (Blueprint $table) {
            $table->dropColumn(['baseline_gross_qty', 'gross_delta_qty', 'baseline_net_qty', 'net_delta_qty']);
        });
        DB::statement('ALTER TABLE mrp_runs DROP CHECK chk_mrp_runs_type');
        Schema::table('mrp_runs', function (Blueprint $table) {
            $table->dropForeign(['source_amendment_id']);
            $table->dropForeign(['baseline_mrp_run_id']);
            $table->dropIndex('idx_mrp_runs_type');
            $table->dropColumn(['run_type', 'source_amendment_id', 'baseline_mrp_run_id']);
        });
    }
};
