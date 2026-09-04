<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('line_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('line_id')->constrained('lines')->restrictOnDelete();
            $table->date('output_date');
            $table->decimal('qty', 18, 4)->default(0);
            $table->decimal('target_qty', 18, 4)->nullable();
            $table->decimal('achievement_pct', 9, 4)->nullable();
            $table->timestamps(6);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unique(['company_id','production_order_id','line_id','output_date'], 'uq_line_outputs_daily');
            $table->index(['company_id','line_id','output_date'], 'idx_line_outputs_line_date');
        });
        DB::statement('ALTER TABLE line_outputs ADD CONSTRAINT chk_line_outputs_qty CHECK (qty >= 0)');
        DB::statement('ALTER TABLE line_outputs ADD CONSTRAINT chk_line_outputs_target CHECK (target_qty IS NULL OR target_qty >= 0)');

        Schema::create('line_output_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('line_output_id')->constrained('line_outputs')->restrictOnDelete();
            $table->foreignId('source_scan_id')->constrained('production_scans')->restrictOnDelete();
            $table->foreignId('bundle_id')->constrained('bundles')->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->timestamp('recorded_at', 6);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->unique('source_scan_id', 'uq_line_output_entries_scan');
            $table->index(['company_id','bundle_id'], 'idx_line_output_entries_bundle');
        });
        DB::statement('ALTER TABLE line_output_entries ADD CONSTRAINT chk_line_output_entries_qty CHECK (qty > 0)');

        Schema::create('finishing_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('bundle_id')->constrained('bundles')->restrictOnDelete();
            $table->foreignId('source_scan_id')->constrained('production_scans')->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->timestamp('completed_at', 6);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->unique(['company_id','bundle_id'], 'uq_finishing_outputs_bundle');
            $table->unique('source_scan_id', 'uq_finishing_outputs_scan');
            $table->index(['company_id','production_order_id','completed_at'], 'idx_finishing_outputs_mo');
        });
        DB::statement('ALTER TABLE finishing_outputs ADD CONSTRAINT chk_finishing_outputs_qty CHECK (qty > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('finishing_outputs');
        Schema::dropIfExists('line_output_entries');
        Schema::dropIfExists('line_outputs');
    }
};
