<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('style_id')->constrained('styles')->restrictOnDelete();
            $table->foreignId('line_id')->constrained('lines')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('target_qty', 18, 4);
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(
                ['company_id', 'line_id', 'period_start', 'period_end', 'sales_order_id', 'style_id'],
                'uq_production_plans_scope'
            );
            $table->index(['company_id', 'period_start', 'period_end'], 'idx_production_plans_period');
            $table->index(['sales_order_id', 'style_id'], 'idx_production_plans_source');
        });

        DB::statement('ALTER TABLE production_plans ADD CONSTRAINT chk_production_plans_period CHECK (period_end >= period_start)');
        DB::statement('ALTER TABLE production_plans ADD CONSTRAINT chk_production_plans_target CHECK (target_qty > 0)');

        Schema::create('line_loadings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('production_plan_id')->constrained('production_plans')->restrictOnDelete();
            $table->foreignId('line_id')->constrained('lines')->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->date('plan_date');
            $table->decimal('planned_qty', 18, 4);
            $table->decimal('capacity_snapshot', 18, 4)->nullable();
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['line_id', 'plan_date', 'production_order_id'], 'uq_line_loadings_line_date_mo');
            $table->index(['company_id', 'plan_date'], 'idx_line_loadings_company_date');
            $table->index('production_plan_id', 'idx_line_loadings_plan');
            $table->index('production_order_id', 'idx_line_loadings_mo');
        });

        DB::statement('ALTER TABLE line_loadings ADD CONSTRAINT chk_line_loadings_qty CHECK (planned_qty > 0)');
        DB::statement('ALTER TABLE line_loadings ADD CONSTRAINT chk_line_loadings_capacity CHECK (capacity_snapshot IS NULL OR capacity_snapshot >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('line_loadings');
        Schema::dropIfExists('production_plans');
    }
};
