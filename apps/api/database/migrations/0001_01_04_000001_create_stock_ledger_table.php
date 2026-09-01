<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('movement_type', 24);
            $table->string('item_type', 8)->default('MATERIAL');
            $table->foreignId('material_id')->nullable()->constrained('materials')->restrictOnDelete();
            $table->foreignId('style_id')->nullable()->constrained('styles')->restrictOnDelete();
            $table->foreignId('colorway_id')->nullable()->constrained('colorways')->restrictOnDelete();
            $table->foreignId('size_id')->nullable()->constrained('sizes')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->string('lot_no', 64)->nullable();
            $table->unsignedBigInteger('roll_id')->nullable();
            $table->string('ownership', 8)->default('COMPANY');
            $table->decimal('qty_in', 18, 4)->default(0);
            $table->decimal('qty_out', 18, 4)->default(0);
            $table->foreignId('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('unit_cost', 19, 6)->nullable();
            $table->decimal('total_cost', 19, 4)->nullable();
            $table->decimal('running_balance', 18, 4)->nullable();
            $table->string('source_document_type', 64);
            $table->unsignedBigInteger('source_document_id');
            $table->unsignedBigInteger('source_document_line_id')->nullable();
            $table->timestamp('created_at', 6);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->index(['company_id', 'material_id', 'warehouse_id', 'created_at'], 'idx_ledger_item_wh_date');
            $table->index(['source_document_type', 'source_document_id'], 'idx_ledger_source');
            $table->index('roll_id', 'idx_ledger_roll');
        });

        DB::statement("ALTER TABLE stock_ledger ADD CONSTRAINT chk_ledger_movement CHECK (movement_type IN ('OPENING','PURCHASE_RECEIPT','PURCHASE_RETURN','QUALITY_RELEASE','TRANSFER_IN','TRANSFER_OUT','MATERIAL_ISSUE','PRODUCTION_RETURN','PRODUCTION_RECEIPT','ADJUSTMENT','OPNAME_ADJUSTMENT','SUBCON_OUT','SUBCON_IN','SHIPMENT'))");
        DB::statement("ALTER TABLE stock_ledger ADD CONSTRAINT chk_ledger_item_type CHECK (item_type IN ('MATERIAL','WIP','FG'))");
        DB::statement("ALTER TABLE stock_ledger ADD CONSTRAINT chk_ledger_ownership CHECK (ownership IN ('COMPANY','BUYER'))");
        DB::statement("ALTER TABLE stock_ledger ADD CONSTRAINT chk_ledger_qty CHECK ((movement_type = 'QUALITY_RELEASE' AND qty_in = 0 AND qty_out = 0) OR (movement_type <> 'QUALITY_RELEASE' AND ((qty_in > 0 AND qty_out = 0) OR (qty_out > 0 AND qty_in = 0))))");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_ledger');
    }
};
