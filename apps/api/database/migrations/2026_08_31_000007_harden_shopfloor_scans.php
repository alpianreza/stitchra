<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_scans', function (Blueprint $table): void {
            $table->unique(['bundle_id', 'operation_id', 'stage', 'direction'], 'uq_scan_bundle_op_stage_direction');
        });
    }

    public function down(): void
    {
        Schema::table('production_scans', fn (Blueprint $table) => $table->dropUnique('uq_scan_bundle_op_stage_direction'));
    }
};
