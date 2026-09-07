<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->text('notes')->nullable();
            $table->text('award_reason')->nullable();
        });
        Schema::create('rfq_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained('rfqs')->restrictOnDelete();
            $table->unsignedInteger('line_no');
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->foreignId('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->foreignId('pr_line_id')->nullable()->constrained('pr_lines')->restrictOnDelete();
            $table->timestamps(6);
            $table->unique(['rfq_id', 'line_no']);
        });
        Schema::create('rfq_suppliers', function (Blueprint $table) {
            $table->foreignId('rfq_id')->constrained('rfqs')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->timestamps(6);
            $table->primary(['rfq_id', 'supplier_id']);
        });
        Schema::table('quotations', function (Blueprint $table) {
            // Nullable: historical quotations have no proven currency/rate snapshot.
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 24, 12)->nullable();
            $table->string('base_currency', 3)->nullable();
            $table->string('quotation_no', 128)->nullable();
            $table->date('quoted_date')->nullable();
            $table->date('valid_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::table('quotation_lines', function (Blueprint $table) {
            $table->foreignId('rfq_line_id')->nullable()->constrained('rfq_lines')->restrictOnDelete();
            $table->unique(['quotation_id', 'rfq_line_id']);
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('rfq_id')->nullable()->constrained('rfqs')->restrictOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained('quotations')->restrictOnDelete();
            $table->unique('rfq_id');
            $table->unique('quotation_id');
        });
        Schema::table('po_lines', function (Blueprint $table) {
            $table->foreignId('quotation_line_id')->nullable()->constrained('quotation_lines')->restrictOnDelete();
            $table->unique('quotation_line_id');
        });
        Schema::table('supplier_returns', function (Blueprint $table) {
            $table->timestamp('posted_at', 6)->nullable();
        });
        Schema::create('supplier_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_return_id')->constrained('supplier_returns')->restrictOnDelete();
            $table->foreignId('receipt_ledger_id')->constrained('stock_ledger')->restrictOnDelete();
            $table->foreignId('gr_line_id')->constrained('gr_lines')->restrictOnDelete();
            $table->foreignId('roll_id')->nullable()->constrained('fabric_rolls')->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->foreignId('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('unit_cost', 19, 6)->nullable();
            $table->timestamps(6);
            $table->unique(['supplier_return_id', 'receipt_ledger_id'], 'uq_supplier_return_receipt');
        });
        Schema::create('putaways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->restrictOnDelete();
            $table->string('status', 16)->default('DRAFT');
            $table->text('notes')->nullable();
            $table->timestamp('posted_at', 6)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps(6);
            $table->unique(['company_id', 'doc_no']);
        });
        Schema::create('putaway_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('putaway_id')->constrained('putaways')->restrictOnDelete();
            $table->foreignId('receipt_ledger_id')->constrained('stock_ledger')->restrictOnDelete();
            $table->foreignId('gr_line_id')->constrained('gr_lines')->restrictOnDelete();
            $table->foreignId('roll_id')->nullable()->constrained('fabric_rolls')->restrictOnDelete();
            $table->foreignId('from_location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->constrained('locations')->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->foreignId('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('unit_cost', 19, 6)->nullable();
            $table->timestamps(6);
            $table->unique(['putaway_id', 'receipt_ledger_id', 'to_location_id'], 'uq_putaway_receipt_destination');
        });

        DB::statement('ALTER TABLE rfq_lines ADD CONSTRAINT chk_rfq_line_qty CHECK (qty > 0)');
        DB::statement('ALTER TABLE supplier_return_lines ADD CONSTRAINT chk_supplier_return_qty CHECK (qty > 0)');
        DB::statement('ALTER TABLE putaway_lines ADD CONSTRAINT chk_putaway_qty CHECK (qty > 0)');
        DB::statement("ALTER TABLE putaways ADD CONSTRAINT chk_putaway_status CHECK (status IN ('DRAFT','POSTED','CANCELLED'))");

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach (['SR', 'PUT'] as $prefix) {
                DB::table('doc_numbering_configs')->insertOrIgnore([
                    'company_id' => $companyId, 'doc_type' => $prefix, 'prefix' => $prefix,
                    'digits' => 6, 'reset_yearly' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('putaway_lines');
        Schema::dropIfExists('putaways');
        Schema::dropIfExists('supplier_return_lines');
        Schema::table('supplier_returns', fn (Blueprint $table) => $table->dropColumn('posted_at'));
        Schema::table('po_lines', function (Blueprint $table) {
            $table->dropForeign(['quotation_line_id']);
            $table->dropUnique(['quotation_line_id']);
            $table->dropColumn('quotation_line_id');
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['rfq_id']);
            $table->dropForeign(['quotation_id']);
            $table->dropUnique(['rfq_id']);
            $table->dropUnique(['quotation_id']);
            $table->dropColumn(['rfq_id', 'quotation_id']);
        });
        Schema::table('quotation_lines', function (Blueprint $table) {
            $table->dropForeign(['rfq_line_id']);
            $table->dropUnique(['quotation_id', 'rfq_line_id']);
            $table->dropColumn('rfq_line_id');
        });
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('currency_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['exchange_rate', 'base_currency', 'quotation_no', 'quoted_date', 'valid_until']);
        });
        Schema::dropIfExists('rfq_suppliers');
        Schema::dropIfExists('rfq_lines');
        Schema::table('rfqs', fn (Blueprint $table) => $table->dropColumn(['notes', 'award_reason']));
        // Numbering config/counters are retained: cancelled document numbers are never reused.
    }
};
