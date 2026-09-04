<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cut_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->unsignedInteger('planned_lay_count');
            $table->decimal('total_qty', 18, 4);
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_cut_plans_doc_no');
            $table->index('production_order_id', 'idx_cut_plans_mo');
        });
        DB::statement('ALTER TABLE cut_plans ADD CONSTRAINT chk_cut_plans_lay_count CHECK (planned_lay_count > 0)');
        DB::statement('ALTER TABLE cut_plans ADD CONSTRAINT chk_cut_plans_total CHECK (total_qty > 0)');

        Schema::create('cut_plan_lays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('cut_plan_id')->constrained('cut_plans')->restrictOnDelete();
            $table->unsignedInteger('lay_sequence');
            $table->foreignId('colorway_id')->constrained('colorways')->restrictOnDelete();
            $table->unsignedInteger('layer_count');
            $table->decimal('estimated_marker_length_m', 10, 3)->nullable();
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['cut_plan_id', 'lay_sequence'], 'uq_cut_plan_lays_sequence');
            $table->index('colorway_id', 'idx_cut_plan_lays_colorway');
        });
        DB::statement('ALTER TABLE cut_plan_lays ADD CONSTRAINT chk_cut_plan_lays_layers CHECK (layer_count > 0)');
        DB::statement('ALTER TABLE cut_plan_lays ADD CONSTRAINT chk_cut_plan_lays_marker CHECK (estimated_marker_length_m IS NULL OR estimated_marker_length_m > 0)');

        Schema::create('cut_plan_lay_ratios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('cut_plan_lay_id')->constrained('cut_plan_lays')->restrictOnDelete();
            $table->foreignId('size_id')->constrained('sizes')->restrictOnDelete();
            $table->decimal('ratio_qty', 18, 4);
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['cut_plan_lay_id', 'size_id'], 'uq_cut_plan_lay_ratios_size');
            $table->index('size_id', 'idx_cut_plan_lay_ratios_size');
        });
        DB::statement('ALTER TABLE cut_plan_lay_ratios ADD CONSTRAINT chk_cut_plan_lay_ratios_qty CHECK (ratio_qty > 0)');

        Schema::table('cut_orders', function (Blueprint $table) {
            $table->foreignId('cut_plan_id')->nullable()->after('production_order_id')
                ->constrained('cut_plans')->restrictOnDelete();
            $table->index('cut_plan_id', 'idx_cut_orders_plan');
        });
    }

    public function down(): void
    {
        Schema::table('cut_orders', function (Blueprint $table) {
            $table->dropForeign(['cut_plan_id']);
            $table->dropIndex('idx_cut_orders_plan');
            $table->dropColumn('cut_plan_id');
        });
        Schema::dropIfExists('cut_plan_lay_ratios');
        Schema::dropIfExists('cut_plan_lays');
        Schema::dropIfExists('cut_plans');
    }
};
