<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mo_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('sales_order_line_id')->constrained('sales_order_lines')->restrictOnDelete();
            $table->foreignId('colorway_id')->constrained('colorways')->restrictOnDelete();
            $table->foreignId('size_id')->constrained('sizes')->restrictOnDelete();
            $table->decimal('qty_planned', 18, 4);
            $table->timestamps(6);

            $table->unique(['production_order_id', 'colorway_id', 'size_id'], 'uq_mo_lines_matrix');
            $table->unique(['production_order_id', 'sales_order_line_id'], 'uq_mo_lines_source');
            $table->index(['company_id', 'production_order_id'], 'idx_mo_lines_company_mo');
            $table->index('sales_order_line_id', 'idx_mo_lines_so_line');
        });

        DB::statement('ALTER TABLE mo_lines ADD CONSTRAINT chk_mo_lines_qty CHECK (qty_planned > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('mo_lines');
    }
};
