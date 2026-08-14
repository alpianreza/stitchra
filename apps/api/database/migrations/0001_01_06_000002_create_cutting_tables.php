<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PF-05: cut order per MO
        Schema::create('cut_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->date('cut_date');
            $table->string('status', 16)->default('DRAFT');
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_cut_orders_doc_no');
            $table->index('production_order_id', 'idx_cut_orders_mo');
        });

        DB::statement("ALTER TABLE cut_orders ADD CONSTRAINT chk_cut_orders_status CHECK (status IN ('DRAFT','IN_PROGRESS','COMPLETED','CANCELLED'))");

        Schema::create('cut_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cut_order_id')->constrained('cut_orders')->restrictOnDelete();
            $table->foreignId('colorway_id')->constrained('colorways')->restrictOnDelete();
            $table->foreignId('size_id')->constrained('sizes')->restrictOnDelete();
            $table->decimal('qty_cut', 18, 4);
            $table->timestamps(6);

            $table->index('cut_order_id', 'idx_cut_order_lines');
        });

        // BR-061: bundle = unit tracking shop floor (barcode)
        Schema::create('bundles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('bundle_no', 40);
            $table->foreignId('cut_order_line_id')->constrained('cut_order_lines')->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->string('current_stage', 12)->default('CUTTING');
            $table->string('status', 16)->default('ACTIVE');
            $table->timestamps(6);

            $table->unique(['company_id', 'bundle_no'], 'uq_bundles_no');
            $table->index(['production_order_id', 'current_stage'], 'idx_bundles_mo_stage');
        });

        DB::statement("ALTER TABLE bundles ADD CONSTRAINT chk_bundles_stage CHECK (current_stage IN ('CUTTING','SEWING','FINISHING','QC','PACKING'))");
        DB::statement("ALTER TABLE bundles ADD CONSTRAINT chk_bundles_status CHECK (status IN ('ACTIVE','COMPLETED','REWORK','REJECTED'))");

        // Marker log — konsumsi aktual kain per roll (BR-031/041)
        Schema::create('marker_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cut_order_id')->constrained('cut_orders')->restrictOnDelete();
            $table->unsignedBigInteger('roll_id');
            $table->decimal('marker_length_m', 10, 3);
            $table->unsignedInteger('plies');
            $table->decimal('qty_fabric_used_m', 18, 4);   // aktual terukur
            $table->decimal('efficiency_pct', 7, 4)->nullable();
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();

            $table->index('cut_order_id', 'idx_marker_logs_cut');
            $table->index('roll_id', 'idx_marker_logs_roll');
        });

        Schema::table('marker_logs', function (Blueprint $table) {
            $table->foreign('roll_id', 'fk_marker_logs_roll')->references('id')->on('fabric_rolls')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('marker_logs', fn (Blueprint $t) => $t->dropForeign('fk_marker_logs_roll'));
        Schema::dropIfExists('marker_logs');
        Schema::dropIfExists('bundles');
        Schema::dropIfExists('cut_order_lines');
        Schema::dropIfExists('cut_orders');
    }
};
