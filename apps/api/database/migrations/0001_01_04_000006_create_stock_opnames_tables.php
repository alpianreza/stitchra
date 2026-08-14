<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PF-12: opname — freeze saldo sistem → count fisik → variance → approval → OPNAME_ADJUSTMENT
        Schema::create('stock_opnames', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('opname_date');
            $table->string('status', 16)->default('DRAFT');
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_stock_opnames_doc_no');
        });

        DB::statement("ALTER TABLE stock_opnames ADD CONSTRAINT chk_opnames_status CHECK (status IN ('DRAFT','COUNTING','SUBMITTED','APPROVED','CANCELLED'))");

        Schema::create('stock_opname_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_opname_id')->constrained('stock_opnames')->restrictOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->string('lot_no', 64)->nullable();
            $table->unsignedBigInteger('roll_id')->nullable();
            $table->decimal('system_qty', 18, 4);      // snapshot saldo sistem saat freeze
            $table->decimal('counted_qty', 18, 4)->nullable();
            $table->decimal('variance_qty', 18, 4)->nullable();  // counted − system
            $table->timestamps(6);

            $table->index('stock_opname_id', 'idx_opname_lines');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_lines');
        Schema::dropIfExists('stock_opnames');
    }
};
