<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PF-09: packing list per shipment plan; isi = karton
        Schema::create('packing_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('production_order_id')->nullable()->constrained('production_orders')->restrictOnDelete();
            $table->string('status', 16)->default('DRAFT');
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_packing_lists_doc_no');
            $table->index('sales_order_id', 'idx_packing_lists_so');
        });

        DB::statement("ALTER TABLE packing_lists ADD CONSTRAINT chk_packing_lists_status CHECK (status IN ('DRAFT','SUBMITTED','APPROVED','SHIPPED','CANCELLED'))");

        // Karton dengan barcode (BR-082: packing hanya dari qty lulus QC)
        Schema::create('cartons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('carton_no', 40);   // barcode
            $table->foreignId('packing_list_id')->constrained('packing_lists')->restrictOnDelete();
            $table->unsignedInteger('seq');
            $table->decimal('gross_weight_kg', 10, 3)->nullable();
            $table->decimal('net_weight_kg', 10, 3)->nullable();
            $table->string('dimension', 32)->nullable();   // PxLxT cm
            $table->timestamps(6);

            $table->unique(['company_id', 'carton_no'], 'uq_cartons_no');
            $table->index('packing_list_id', 'idx_cartons_pl');
        });

        // Isi karton: style×color×size×qty (ratio check vs SO matrix — BR-021/082)
        Schema::create('carton_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carton_id')->constrained('cartons')->restrictOnDelete();
            $table->foreignId('style_id')->constrained('styles')->restrictOnDelete();
            $table->foreignId('colorway_id')->constrained('colorways')->restrictOnDelete();
            $table->foreignId('size_id')->constrained('sizes')->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->timestamps(6);

            $table->index('carton_id', 'idx_carton_lines');
            $table->index(['style_id', 'colorway_id', 'size_id'], 'idx_carton_lines_matrix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carton_lines');
        Schema::dropIfExists('cartons');
        Schema::dropIfExists('packing_lists');
    }
};
