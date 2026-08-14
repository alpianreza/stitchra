<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // BR-041/060: issue material ke MO dari reservasi (fabric aktual, trim backflush)
        Schema::create('material_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('mode', 12)->default('ACTUAL');   // ACTUAL / BACKFLUSH (BR-041)
            $table->string('status', 16)->default('POSTED');
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_material_issues_doc_no');
            $table->index('production_order_id', 'idx_material_issues_mo');
        });

        DB::statement("ALTER TABLE material_issues ADD CONSTRAINT chk_mi_mode CHECK (mode IN ('ACTUAL','BACKFLUSH'))");
        DB::statement("ALTER TABLE material_issues ADD CONSTRAINT chk_mi_status CHECK (status IN ('POSTED','CANCELLED'))");

        Schema::create('material_issue_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_issue_id')->constrained('material_issues')->restrictOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->foreignId('stock_reservation_id')->nullable()->constrained('stock_reservations')->restrictOnDelete();
            $table->unsignedBigInteger('roll_id')->nullable();   // fabric aktual per roll (BR-041)
            $table->string('lot_no', 64)->nullable();
            $table->decimal('qty', 18, 4);
            $table->foreignId('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->timestamps(6);

            $table->index('material_issue_id', 'idx_mi_lines');
            $table->index('roll_id', 'idx_mi_lines_roll');
        });

        Schema::table('material_issue_lines', function (Blueprint $table) {
            $table->foreign('roll_id', 'fk_mi_lines_roll')->references('id')->on('fabric_rolls')->restrictOnDelete();
        });

        // BR-042: leftover kembali ke inventory sebagai stok available (bukan dihapus)
        Schema::create('fabric_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('roll_id')->constrained('fabric_rolls')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('qty_returned_meter', 18, 4);
            $table->string('reason')->nullable();
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_fabric_returns_doc_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fabric_returns');
        Schema::table('material_issue_lines', fn (Blueprint $t) => $t->dropForeign('fk_mi_lines_roll'));
        Schema::dropIfExists('material_issue_lines');
        Schema::dropIfExists('material_issues');
    }
};
