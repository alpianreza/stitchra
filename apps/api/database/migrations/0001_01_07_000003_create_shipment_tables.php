<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PF-10: shipping instruction → shipment; BR-021: toleransi over/under
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('packing_list_id')->nullable()->constrained('packing_lists')->restrictOnDelete();
            $table->date('ship_date');
            $table->string('forwarder')->nullable();
            $table->string('booking_no')->nullable();
            $table->string('container_no')->nullable();
            $table->string('vessel_flight')->nullable();
            $table->string('port_of_loading')->nullable();
            $table->string('port_of_discharge')->nullable();
            // BR-021: hasil cek toleransi terhadap SO
            $table->string('tolerance_check', 16)->default('PENDING');  // PENDING/OK/OVER/UNDER
            $table->boolean('over_tolerance_approved')->default(false); // wajib approval bila di luar toleransi
            $table->string('status', 16)->default('DRAFT');
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_shipments_doc_no');
            $table->index('sales_order_id', 'idx_shipments_so');
        });

        DB::statement("ALTER TABLE shipments ADD CONSTRAINT chk_shipments_status CHECK (status IN ('DRAFT','READY','SHIPPED','CANCELLED'))");
        DB::statement("ALTER TABLE shipments ADD CONSTRAINT chk_shipments_tolerance CHECK (tolerance_check IN ('PENDING','OK','OVER','UNDER'))");

        // Baris shipment: agregat per matrix dari packing list
        Schema::create('shipment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->foreignId('style_id')->constrained('styles')->restrictOnDelete();
            $table->foreignId('colorway_id')->constrained('colorways')->restrictOnDelete();
            $table->foreignId('size_id')->constrained('sizes')->restrictOnDelete();
            $table->decimal('qty_shipped', 18, 4);
            $table->timestamps(6);

            $table->index('shipment_id', 'idx_shipment_lines');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_lines');
        Schema::dropIfExists('shipments');
    }
};
