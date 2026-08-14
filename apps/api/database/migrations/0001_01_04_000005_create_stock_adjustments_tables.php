<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // BR-017: koreksi stok hanya via adjustment ber-approval (tidak edit langsung)
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->text('reason');
            $table->string('status', 16)->default('DRAFT');
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_stock_adjustments_doc_no');
        });

        DB::statement("ALTER TABLE stock_adjustments ADD CONSTRAINT chk_adjustments_status CHECK (status IN ('DRAFT','SUBMITTED','APPROVED','REJECTED','CANCELLED'))");

        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained('stock_adjustments')->restrictOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->string('lot_no', 64)->nullable();
            $table->unsignedBigInteger('roll_id')->nullable();
            $table->decimal('qty_delta', 18, 4);            // + tambah / − kurang
            $table->decimal('unit_cost', 19, 6)->nullable(); // wajib bila qty_delta > 0 (masuk moving average)
            $table->foreignId('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->timestamps(6);

            $table->index('stock_adjustment_id', 'idx_adjustment_lines');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_lines');
        Schema::dropIfExists('stock_adjustments');
    }
};
