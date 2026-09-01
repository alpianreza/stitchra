<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cut_order_lines', function (Blueprint $table): void {
            $table->unique(['cut_order_id', 'colorway_id', 'size_id'], 'uq_cut_lines_matrix');
        });
        Schema::table('mo_material_allocations', function (Blueprint $table): void {
            $table->decimal('qty_consumed', 18, 4)->default(0)->after('qty_issued');
            $table->decimal('actual_consumption_per_pcs', 18, 6)->nullable()->after('qty_consumed');
        });
    }

    public function down(): void
    {
        Schema::table('mo_material_allocations', function (Blueprint $table): void {
            $table->dropColumn(['qty_consumed', 'actual_consumption_per_pcs']);
        });
        Schema::table('cut_order_lines', fn (Blueprint $table) => $table->dropUnique('uq_cut_lines_matrix'));
    }
};
