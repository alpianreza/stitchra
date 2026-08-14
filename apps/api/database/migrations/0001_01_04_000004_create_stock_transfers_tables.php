<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PF-12: transfer antar warehouse/location ⇒ ledger TRANSFER_OUT + TRANSFER_IN
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 16)->default('DRAFT');
            $table->text('notes')->nullable();
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_stock_transfers_doc_no');
        });

        DB::statement("ALTER TABLE stock_transfers ADD CONSTRAINT chk_transfers_status CHECK (status IN ('DRAFT','SUBMITTED','APPROVED','IN_TRANSIT','RECEIVED','CANCELLED'))");

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->restrictOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->string('lot_no', 64)->nullable();
            $table->unsignedBigInteger('roll_id')->nullable();
            $table->decimal('qty', 18, 4);
            $table->foreignId('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->timestamps(6);

            $table->index('stock_transfer_id', 'idx_transfer_lines');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
    }
};
