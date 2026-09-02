<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packing_lists', function (Blueprint $table) {
            $table->foreignId('qc_inspection_id')->nullable()->after('production_order_id')
                ->constrained('qc_inspections')->restrictOnDelete();
            $table->index(['company_id', 'qc_inspection_id'], 'idx_packing_lists_qc_source');
        });
    }

    public function down(): void
    {
        Schema::table('packing_lists', function (Blueprint $table) {
            $table->dropIndex('idx_packing_lists_qc_source');
            $table->dropConstrainedForeignId('qc_inspection_id');
        });
    }
};
