<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packing_instructions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('pack_type', 16);
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unique(['sales_order_id', 'version'], 'uq_packing_instruction_version');
            $table->index(['company_id', 'sales_order_id', 'is_active'], 'idx_packing_instruction_active');
        });
        DB::statement("ALTER TABLE packing_instructions ADD CONSTRAINT chk_packing_instruction_type CHECK (pack_type IN ('SOLID','RATIO','MIXED'))");

        Schema::create('packing_instruction_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('packing_instruction_id')->constrained('packing_instructions')->restrictOnDelete();
            $table->foreignId('style_id')->constrained('styles')->restrictOnDelete();
            $table->foreignId('colorway_id')->constrained('colorways')->restrictOnDelete();
            $table->foreignId('size_id')->constrained('sizes')->restrictOnDelete();
            $table->unsignedInteger('ratio_qty')->default(1);
            $table->timestamps(6);
            $table->unique(['packing_instruction_id', 'style_id', 'colorway_id', 'size_id'], 'uq_packing_instruction_matrix');
        });
        DB::statement('ALTER TABLE packing_instruction_lines ADD CONSTRAINT chk_packing_instruction_ratio CHECK (ratio_qty > 0)');

        Schema::table('packing_lists', function (Blueprint $table) {
            $table->foreignId('packing_instruction_id')->nullable()->after('qc_inspection_id')->constrained('packing_instructions')->restrictOnDelete();
            $table->index('packing_instruction_id', 'idx_packing_lists_instruction');
        });
    }

    public function down(): void
    {
        Schema::table('packing_lists', function (Blueprint $table) {
            $table->dropForeign(['packing_instruction_id']);
            $table->dropIndex('idx_packing_lists_instruction');
            $table->dropColumn('packing_instruction_id');
        });
        Schema::dropIfExists('packing_instruction_lines');
        Schema::dropIfExists('packing_instructions');
    }
};
