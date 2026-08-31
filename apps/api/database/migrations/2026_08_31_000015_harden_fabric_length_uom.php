<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fabric_rolls', function (Blueprint $table): void {
            $table->unsignedBigInteger('use_uom_id')->nullable()->after('material_id');
            $table->decimal('qty_use_actual', 18, 4)->nullable()->after('qty_meter_actual');
            $table->decimal('qty_remaining_use', 18, 4)->nullable()->after('qty_remaining_meter');
            $table->foreign('use_uom_id', 'fk_fabric_rolls_use_uom')->references('id')->on('uoms')->restrictOnDelete();
        });
        Schema::table('marker_logs', function (Blueprint $table): void {
            $table->unsignedBigInteger('uom_id')->nullable()->after('roll_id');
            $table->decimal('marker_length_use', 18, 4)->nullable()->after('marker_length_m');
            $table->decimal('qty_fabric_used_use', 18, 4)->nullable()->after('qty_fabric_used_m');
            $table->foreign('uom_id', 'fk_marker_logs_uom')->references('id')->on('uoms')->restrictOnDelete();
        });
        Schema::table('fabric_returns', function (Blueprint $table): void {
            $table->unsignedBigInteger('uom_id')->nullable()->after('warehouse_id');
            $table->decimal('qty_returned_use', 18, 4)->nullable()->after('qty_returned_meter');
            $table->decimal('qty_dispatched_use', 18, 4)->nullable()->after('qty_returned_use');
            $table->decimal('qty_consumed_use', 18, 4)->nullable()->after('qty_dispatched_use');
            $table->foreign('uom_id', 'fk_fabric_returns_uom')->references('id')->on('uoms')->restrictOnDelete();
            $table->unique(['production_order_id', 'roll_id'], 'uq_fabric_return_mo_roll');
        });

        DB::statement('UPDATE fabric_rolls fr JOIN materials m ON m.id = fr.material_id SET fr.use_uom_id = m.use_uom_id, fr.qty_use_actual = fr.qty_meter_actual, fr.qty_remaining_use = fr.qty_remaining_meter');
        DB::statement('UPDATE marker_logs ml JOIN fabric_rolls fr ON fr.id = ml.roll_id SET ml.uom_id = fr.use_uom_id, ml.marker_length_use = ml.marker_length_m, ml.qty_fabric_used_use = ml.qty_fabric_used_m');
        DB::statement('UPDATE fabric_returns r JOIN fabric_rolls fr ON fr.id = r.roll_id SET r.uom_id = fr.use_uom_id, r.qty_returned_use = r.qty_returned_meter');

        Schema::create('fabric_dispatch_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('roll_id')->constrained('fabric_rolls')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('qty_dispatched', 18, 4)->default(0);
            $table->decimal('qty_consumed', 18, 4)->default(0);
            $table->decimal('qty_returned', 18, 4)->default(0);
            $table->timestamps(6);
            $table->unique(['production_order_id', 'roll_id'], 'uq_fabric_dispatch_mo_roll');
            $table->index(['company_id', 'roll_id'], 'idx_fabric_dispatch_company_roll');
        });
        DB::statement('ALTER TABLE fabric_dispatch_balances ADD CONSTRAINT chk_fabric_dispatch_qty CHECK (qty_dispatched >= 0 AND qty_consumed >= 0 AND qty_returned >= 0 AND qty_consumed + qty_returned <= qty_dispatched)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fabric_dispatch_balances');
        Schema::table('fabric_returns', function (Blueprint $table): void {
            $table->dropUnique('uq_fabric_return_mo_roll');
            $table->dropForeign('fk_fabric_returns_uom');
            $table->dropColumn(['uom_id', 'qty_returned_use', 'qty_dispatched_use', 'qty_consumed_use']);
        });
        Schema::table('marker_logs', function (Blueprint $table): void {
            $table->dropForeign('fk_marker_logs_uom');
            $table->dropColumn(['uom_id', 'marker_length_use', 'qty_fabric_used_use']);
        });
        Schema::table('fabric_rolls', function (Blueprint $table): void {
            $table->dropForeign('fk_fabric_rolls_use_uom');
            $table->dropColumn(['use_uom_id', 'qty_use_actual', 'qty_remaining_use']);
        });
    }
};
