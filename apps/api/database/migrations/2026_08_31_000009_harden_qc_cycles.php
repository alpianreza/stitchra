<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qc_inspections', function (Blueprint $table): void {
            $table->unique(['production_order_id','stage','cycle'], 'uq_qc_mo_stage_cycle');
        });
        DB::statement('ALTER TABLE qc_inspections ADD CONSTRAINT chk_qc_lot_positive CHECK (lot_qty > 0)');
        DB::statement('ALTER TABLE qc_inspection_lines ADD CONSTRAINT chk_qc_defect_qty_positive CHECK (qty > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE qc_inspection_lines DROP CHECK chk_qc_defect_qty_positive');
        DB::statement('ALTER TABLE qc_inspections DROP CHECK chk_qc_lot_positive');
        Schema::table('qc_inspections', fn (Blueprint $table) => $table->dropUnique('uq_qc_mo_stage_cycle'));
    }
};
