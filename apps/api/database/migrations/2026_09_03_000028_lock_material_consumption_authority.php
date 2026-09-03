<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bom_lines', function (Blueprint $table): void {
            $table->string('backflush_stage', 32)->nullable()->after('is_backflush');
        });

        Schema::table('mo_material_allocations', function (Blueprint $table): void {
            $table->unsignedBigInteger('uom_id')->nullable()->after('material_id');
            $table->string('backflush_stage', 32)->nullable()->after('is_backflush');
            $table->foreign('uom_id', 'fk_mo_allocations_uom')->references('id')->on('uoms')->restrictOnDelete();
            $table->index(['production_order_id', 'is_backflush', 'backflush_stage'], 'idx_mo_alloc_backflush_stage');
        });

        Schema::table('material_issue_lines', function (Blueprint $table): void {
            $table->string('backflush_stage', 32)->nullable()->after('uom_id');
        });
    }

    public function down(): void
    {
        Schema::table('material_issue_lines', fn (Blueprint $table) => $table->dropColumn('backflush_stage'));
        Schema::table('mo_material_allocations', function (Blueprint $table): void {
            $table->dropIndex('idx_mo_alloc_backflush_stage');
            $table->dropForeign('fk_mo_allocations_uom');
            $table->dropColumn(['uom_id', 'backflush_stage']);
        });
        Schema::table('bom_lines', fn (Blueprint $table) => $table->dropColumn('backflush_stage'));
    }
};
