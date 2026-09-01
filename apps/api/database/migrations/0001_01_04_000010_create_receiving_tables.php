<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('received_date');
            $table->string('delivery_note_no')->nullable();
            $table->string('status', 16)->default('DRAFT');
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unique(['company_id', 'doc_no'], 'uq_gr_doc_no');
            $table->index('purchase_order_id', 'idx_gr_po');
        });
        DB::statement("ALTER TABLE goods_receipts ADD CONSTRAINT chk_gr_status CHECK (status IN ('DRAFT','SUBMITTED','POSTED','CANCELLED'))");

        Schema::create('gr_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->restrictOnDelete();
            $table->foreignId('po_line_id')->constrained('po_lines')->restrictOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->decimal('qty_received', 18, 4);
            $table->foreignId('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('unit_price', 19, 6);
            $table->string('status', 20)->default('QUALITY_HOLD');
            $table->timestamps(6);
            $table->index('goods_receipt_id', 'idx_gr_lines_gr');
            $table->index('po_line_id', 'idx_gr_lines_po_line');
        });
        DB::statement("ALTER TABLE gr_lines ADD CONSTRAINT chk_gr_lines_status CHECK (status IN ('QUALITY_HOLD','PARTIAL','RELEASED','REJECTED_RETURNED'))");

        Schema::create('fabric_rolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('roll_no', 64);
            $table->foreignId('gr_line_id')->constrained('gr_lines')->restrictOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->string('lot_no', 64)->nullable();
            $table->foreignId('shade_group_id')->nullable()->constrained('shade_groups')->restrictOnDelete();
            $table->decimal('qty_buy', 18, 4);
            $table->decimal('qty_meter_actual', 18, 4);
            $table->decimal('conversion_rate', 18, 6);
            $table->decimal('gsm_actual', 10, 2)->nullable();
            $table->decimal('width_actual_cm', 10, 2)->nullable();
            $table->decimal('qty_remaining_meter', 18, 4);
            $table->string('status', 20)->default('QUALITY_HOLD');
            $table->timestamps(6);
            $table->unique(['company_id', 'roll_no'], 'uq_fabric_rolls_roll_no');
            $table->index('gr_line_id', 'idx_fabric_rolls_gr_line');
            $table->index(['material_id', 'status'], 'idx_fabric_rolls_material');
            $table->index('shade_group_id', 'idx_fabric_rolls_shade');
        });
        DB::statement("ALTER TABLE fabric_rolls ADD CONSTRAINT chk_fabric_rolls_status CHECK (status IN ('QUALITY_HOLD','RELEASED','REJECTED_RETURNED','CONSUMED'))");

        Schema::table('stock_ledger', fn (Blueprint $table) => $table->foreign('roll_id', 'fk_ledger_roll')->references('id')->on('fabric_rolls')->restrictOnDelete());
        Schema::table('stock_balances', fn (Blueprint $table) => $table->foreign('roll_id', 'fk_balances_roll')->references('id')->on('fabric_rolls')->restrictOnDelete());
        Schema::table('supplier_invoice_lines', fn (Blueprint $table) => $table->foreign('gr_line_id', 'fk_inv_lines_gr_line')->references('id')->on('gr_lines')->restrictOnDelete());
    }

    public function down(): void
    {
        Schema::table('supplier_invoice_lines', fn (Blueprint $t) => $t->dropForeign('fk_inv_lines_gr_line'));
        Schema::table('stock_balances', fn (Blueprint $t) => $t->dropForeign('fk_balances_roll'));
        Schema::table('stock_ledger', fn (Blueprint $t) => $t->dropForeign('fk_ledger_roll'));
        Schema::dropIfExists('fabric_rolls');
        Schema::dropIfExists('gr_lines');
        Schema::dropIfExists('goods_receipts');
    }
};
