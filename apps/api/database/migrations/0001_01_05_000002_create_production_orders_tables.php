<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MO — per style dari SO (qty diagregasi); lifecycle shop floor (subset BR-012)
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('style_id')->constrained('styles')->restrictOnDelete();
            $table->foreignId('bom_version_id')->nullable()->constrained('bom_versions')->restrictOnDelete(); // snapshot versi (BR-030)
            $table->foreignId('routing_version_id')->nullable()->constrained('routing_versions')->restrictOnDelete();
            $table->foreignId('line_id')->nullable()->constrained('lines')->restrictOnDelete();
            $table->decimal('qty_planned', 18, 4);
            $table->decimal('qty_produced', 18, 4)->default(0);
            $table->date('planned_start')->nullable();
            $table->date('planned_end')->nullable();
            $table->date('actual_start')->nullable();
            $table->date('actual_end')->nullable();
            $table->string('status', 16)->default('PLANNED');
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_production_orders_doc_no');
            $table->index(['company_id', 'status'], 'idx_mo_status');
            $table->index('sales_order_id', 'idx_mo_so');
            $table->index('style_id', 'idx_mo_style');
        });

        DB::statement("ALTER TABLE production_orders ADD CONSTRAINT chk_mo_status CHECK (status IN ('PLANNED','RELEASED','CUTTING','SEWING','FINISHING','QC','PACKED','CLOSED','CANCELLED'))");

        // Alokasi material per MO (dari BOM explode saat release) — pasangan reservasi
        Schema::create('mo_material_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->decimal('qty_required', 18, 4);    // qty_planned × grossPerPcs
            $table->decimal('qty_reserved', 18, 4)->default(0);
            $table->decimal('qty_issued', 18, 4)->default(0);
            $table->boolean('is_backflush')->default(false);   // BR-041
            $table->timestamps(6);

            $table->unique(['production_order_id', 'material_id'], 'uq_mo_allocations');
        });

        // FK closure Phase 4 → Phase 5
        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->foreign('mo_id', 'fk_reservations_mo')
                ->references('id')->on('production_orders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_reservations', fn (Blueprint $t) => $t->dropForeign('fk_reservations_mo'));
        Schema::dropIfExists('mo_material_allocations');
        Schema::dropIfExists('production_orders');
    }
};
