<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mrp_requirements', function (Blueprint $table): void {
            $table->unique(['mrp_run_id', 'material_id'], 'uq_mrp_requirements_run_material');
        });
    }

    public function down(): void
    {
        Schema::table('mrp_requirements', fn (Blueprint $table) => $table->dropUnique('uq_mrp_requirements_run_material'));
    }
};
