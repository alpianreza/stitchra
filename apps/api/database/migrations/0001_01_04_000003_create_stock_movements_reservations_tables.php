<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Header dokumen movement — mengelompokkan ledger entries per dokumen (BR-013)
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->string('movement_type', 24);
            $table->string('source_document_type', 64);
            $table->unsignedBigInteger('source_document_id');
            $table->timestamps(6);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->unique(['company_id', 'doc_no'], 'uq_stock_movements_doc_no');
            $table->index(['source_document_type', 'source_document_id'], 'idx_movements_source');
        });

        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT chk_movements_type CHECK (movement_type IN ('OPENING','PURCHASE_RECEIPT','PURCHASE_RETURN','QUALITY_RELEASE','TRANSFER_IN','TRANSFER_OUT','MATERIAL_ISSUE','PRODUCTION_RETURN','PRODUCTION_RECEIPT','ADJUSTMENT','OPNAME_ADJUSTMENT','SUBCON_OUT','SUBCON_IN','SHIPMENT'))");

        // BR-006/060: hard reservation saat MO release.
        // CATATAN: mo_id plain + index — FK ke production_orders ditambahkan Phase 5.
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('mo_id');                   // → production_orders (Phase 5)
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->string('lot_no', 64)->nullable();
            $table->unsignedBigInteger('roll_id')->nullable();
            $table->string('ownership', 8)->default('COMPANY');
            $table->decimal('qty_reserved', 18, 4);
            $table->decimal('qty_issued', 18, 4)->default(0);
            $table->string('status', 16)->default('ACTIVE');
            $table->timestamps(6);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->index('mo_id', 'idx_reservations_mo');
            $table->index(['material_id', 'warehouse_id', 'status'], 'idx_reservations_item');
        });

        DB::statement("ALTER TABLE stock_reservations ADD CONSTRAINT chk_reservations_status CHECK (status IN ('ACTIVE','PARTIAL_ISSUED','FULLY_ISSUED','RELEASED'))");
        DB::statement("ALTER TABLE stock_reservations ADD CONSTRAINT chk_reservations_qty CHECK (qty_reserved >= 0 AND qty_issued >= 0 AND qty_issued <= qty_reserved)");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_movements');
    }
};
