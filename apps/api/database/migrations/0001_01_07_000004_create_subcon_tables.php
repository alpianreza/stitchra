<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Subcontracting CMT — BR-090: stok di subcon = in_transit_subcon; BR-091: receipt per MO+operation
        Schema::create('subcon_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();   // type=SUBCON
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('operation_id')->nullable()->constrained('operations')->restrictOnDelete(); // BR-091
            $table->date('sent_date')->nullable();
            $table->date('expected_return')->nullable();
            $table->decimal('fee_per_pcs', 19, 6)->default(0);   // fee jasa — ke actual costing (BR-080)
            $table->string('status', 16)->default('DRAFT');
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_subcon_orders_doc_no');
            $table->index('production_order_id', 'idx_subcon_mo');
        });

        DB::statement("ALTER TABLE subcon_orders ADD CONSTRAINT chk_subcon_status CHECK (status IN ('DRAFT','SENT','PARTIAL_RETURNED','RETURNED','CLOSED','CANCELLED'))");

        Schema::create('subcon_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subcon_order_id')->constrained('subcon_orders')->restrictOnDelete();
            $table->foreignId('material_id')->nullable()->constrained('materials')->restrictOnDelete();  // bahan pendamping (bila ada)
            $table->foreignId('bundle_id')->nullable()->constrained('bundles')->restrictOnDelete();      // WIP yang dikirim
            $table->decimal('qty_sent', 18, 4);
            $table->decimal('qty_returned', 18, 4)->default(0);
            $table->foreignId('uom_id')->nullable()->constrained('uoms')->restrictOnDelete();
            $table->timestamps(6);

            $table->index('subcon_order_id', 'idx_subcon_lines');
        });

        // Tracking fee per return — masuk actual costing subcon (BR-080)
        Schema::create('subcon_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subcon_order_id')->constrained('subcon_orders')->restrictOnDelete();
            $table->date('return_date');
            $table->decimal('qty_returned', 18, 4);
            $table->decimal('fee_per_pcs', 19, 6);
            $table->decimal('total_fee', 19, 4);
            $table->timestamps(6);

            $table->index('subcon_order_id', 'idx_subcon_fees');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subcon_fees');
        Schema::dropIfExists('subcon_order_lines');
        Schema::dropIfExists('subcon_orders');
    }
};
