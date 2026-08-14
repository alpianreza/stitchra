<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // BR-006: saldo materialized dari ledger; CHECK >= 0 (stok tidak pernah negatif)
        // Kunci: item × warehouse × location × lot × roll × ownership
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
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
            $table->decimal('on_hand', 18, 4)->default(0);
            $table->decimal('reserved', 18, 4)->default(0);
            $table->decimal('quality_hold', 18, 4)->default(0);
            $table->decimal('in_transit_subcon', 18, 4)->default(0);
            $table->decimal('avg_cost', 19, 6)->nullable();        // BR-005: moving average
            $table->timestamps(6);

            $table->unique(
                ['company_id', 'item_type', 'material_id', 'style_id', 'colorway_id', 'size_id', 'warehouse_id', 'location_id', 'lot_no', 'roll_id', 'ownership'],
                'uq_stock_balances_key'
            );
            $table->index(['material_id', 'warehouse_id'], 'idx_balances_item_wh');
        });

        DB::statement("ALTER TABLE stock_balances ADD CONSTRAINT chk_balances_nonneg CHECK (on_hand >= 0 AND reserved >= 0 AND quality_hold >= 0 AND in_transit_subcon >= 0)");
        DB::statement("ALTER TABLE stock_balances ADD CONSTRAINT chk_balances_ownership CHECK (ownership IN ('COMPANY','BUYER'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
    }
};
