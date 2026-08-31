<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartons', fn (Blueprint $table) => $table->unique(['packing_list_id','seq'],'uq_cartons_pl_seq'));
        Schema::table('carton_lines', fn (Blueprint $table) => $table->unique(['carton_id','style_id','colorway_id','size_id'],'uq_carton_lines_matrix'));
        DB::statement('ALTER TABLE carton_lines ADD CONSTRAINT chk_carton_line_qty_positive CHECK (qty > 0)');
    }
    public function down(): void
    {
        DB::statement('ALTER TABLE carton_lines DROP CHECK chk_carton_line_qty_positive');
        Schema::table('carton_lines', fn (Blueprint $table) => $table->dropUnique('uq_carton_lines_matrix'));
        Schema::table('cartons', fn (Blueprint $table) => $table->dropUnique('uq_cartons_pl_seq'));
    }
};
