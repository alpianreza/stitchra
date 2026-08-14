<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 18, 6)->nullable();   // BR-102
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->string('payment_term')->nullable();
            $table->decimal('total_amount', 19, 4)->default(0);
            $table->string('status', 16)->default('DRAFT');
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_po_doc_no');
            $table->index(['company_id', 'supplier_id', 'status'], 'idx_po_supplier_status');
        });

        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT chk_po_status CHECK (status IN ('DRAFT','SUBMITTED','APPROVED','PARTIAL_RECEIVED','RECEIVED','CLOSED','REJECTED','CANCELLED'))");

        Schema::create('po_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->unsignedInteger('line_no');
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->decimal('qty', 18, 4);                 // dalam UOM beli
            $table->foreignId('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('unit_price', 19, 6);
            $table->decimal('received_qty', 18, 4)->default(0);  // agregat dari GR (BR-051)
            $table->unsignedBigInteger('pr_line_id')->nullable(); // trace ke PR (BR-120)
            $table->timestamps(6);

            $table->unique(['purchase_order_id', 'line_no'], 'uq_po_lines');
            $table->index('material_id', 'idx_po_lines_material');
        });

        // BR-050: supplier invoice + 3-way match
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $table->string('supplier_invoice_no')->nullable();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->decimal('total_amount', 19, 4);
            $table->string('match_status', 16)->default('PENDING');  // 3-way match (BR-050)
            $table->string('status', 16)->default('DRAFT');
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_supplier_invoices_doc_no');
            $table->index(['company_id', 'supplier_id'], 'idx_supplier_invoices_supplier');
        });

        DB::statement("ALTER TABLE supplier_invoices ADD CONSTRAINT chk_supplier_invoices_match CHECK (match_status IN ('PENDING','MATCHED','MISMATCH'))");
        DB::statement("ALTER TABLE supplier_invoices ADD CONSTRAINT chk_supplier_invoices_status CHECK (status IN ('DRAFT','SUBMITTED','APPROVED','REJECTED','PAID','CANCELLED'))");

        Schema::create('supplier_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_invoice_id')->constrained('supplier_invoices')->restrictOnDelete();
            $table->foreignId('po_line_id')->nullable()->constrained('po_lines')->restrictOnDelete();
            $table->unsignedBigInteger('gr_line_id')->nullable();   // → gr_lines (batch receiving)
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_price', 19, 6);
            $table->decimal('amount', 19, 4);
            $table->timestamps(6);

            $table->index('supplier_invoice_id', 'idx_supplier_invoice_lines');
            $table->index('po_line_id', 'idx_supplier_invoice_lines_po');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_lines');
        Schema::dropIfExists('supplier_invoices');
        Schema::dropIfExists('po_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
