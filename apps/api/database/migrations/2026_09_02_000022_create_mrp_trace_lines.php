<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // BR-121: trace perhitungan MRP — requirement → SO line → BOM line (kontribusi gross)
        // Konvensi line-level (seperti pr_lines/bom_lines): tenant via dokumen induk (mrp_runs.company_id).
        Schema::create('mrp_trace_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mrp_requirement_id')->constrained('mrp_requirements')->restrictOnDelete();
            $table->foreignId('sales_order_line_id')->constrained('sales_order_lines')->restrictOnDelete();
            $table->foreignId('bom_line_id')->constrained('bom_lines')->restrictOnDelete();
            $table->decimal('gross_qty', 18, 4);   // kontribusi gross dari SO line ini via BOM line ini
            $table->timestamps(6);

            $table->index('mrp_requirement_id', 'idx_mrp_trace_requirement');
            $table->index('sales_order_line_id', 'idx_mrp_trace_so_line');
        });

        DB::statement('ALTER TABLE mrp_trace_lines ADD CONSTRAINT chk_mrp_trace_qty CHECK (gross_qty > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('mrp_trace_lines');
    }
};
