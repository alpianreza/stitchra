<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_inventory_valuations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->foreignId('shipment_line_id')->constrained('shipment_lines')->restrictOnDelete();
            $table->foreignId('packing_list_id')->constrained('packing_lists')->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('production_receipt_movement_id')->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('shipment_movement_id')->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('shipment_ledger_id')->constrained('stock_ledger')->restrictOnDelete();
            $table->foreignId('stock_balance_id')->constrained('stock_balances')->restrictOnDelete();
            $table->string('item_type', 8)->default('FG');
            $table->foreignId('style_id')->constrained('styles')->restrictOnDelete();
            $table->foreignId('colorway_id')->nullable()->constrained('colorways')->restrictOnDelete();
            $table->foreignId('size_id')->nullable()->constrained('sizes')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('ownership', 8)->default('COMPANY');
            $table->decimal('shipment_quantity', 18, 4);
            $table->decimal('moving_average_unit_cost', 19, 6);
            $table->decimal('shipment_inventory_cost', 19, 4);
            $table->string('currency', 3);
            $table->string('cost_method', 24)->default('MOVING_AVERAGE');
            $table->string('valuation_event', 32)->default('ITS_SHIPMENT_OUT');
            $table->unsignedInteger('valuation_version')->default(1);
            $table->decimal('on_hand_before', 18, 4);
            $table->char('source_hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('valued_at', 6);
            $table->timestamp('created_at', 6)->useCurrent();
            $table->unique(['company_id','shipment_id','shipment_line_id','valuation_event'], 'uq_shipment_inventory_valuation_event');
            $table->unique(['company_id','shipment_line_id','source_hash'], 'uq_shipment_inventory_valuation_source');
            $table->index(['company_id','shipment_movement_id'], 'idx_shipment_valuation_its');
            $table->index(['company_id','production_order_id','valued_at'], 'idx_shipment_valuation_mo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_inventory_valuations');
    }
};
