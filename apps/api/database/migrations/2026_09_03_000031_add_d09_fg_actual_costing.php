<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fg_actual_costings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('actual_cost_freeze_id')->unique()->constrained('actual_cost_freezes')->restrictOnDelete();
            $table->string('valuation_object', 16)->default('FG_ACTUAL');
            $table->unsignedInteger('costing_version');
            $table->decimal('fg_received_quantity', 18, 4);
            $table->decimal('actual_total_cost', 19, 4);
            $table->decimal('actual_cost_per_pcs', 19, 4);
            $table->decimal('standard_cost_per_pcs', 19, 4);
            $table->decimal('provisional_fg_value', 19, 4);
            $table->decimal('component_variance_total', 19, 4);
            $table->string('currency', 3);
            $table->string('calculation_version', 32);
            $table->char('standard_snapshot_hash', 64);
            $table->char('source_hash', 64);
            $table->char('calculation_hash', 64);
            $table->json('source_evidence');
            $table->json('completeness');
            $table->string('status', 24)->default('PENDING_FREEZE');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('frozen_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['company_id','production_order_id','valuation_object','costing_version'], 'uq_fg_actual_costing_version');
            $table->unique(['company_id','production_order_id','valuation_object','source_hash'], 'uq_fg_actual_costing_source');
            $table->index(['company_id','status','created_at'], 'idx_fg_actual_costing_status');
        });

        Schema::create('fg_actual_costing_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('fg_actual_costing_id')->constrained('fg_actual_costings')->restrictOnDelete();
            $table->string('component', 16);
            $table->string('completeness_status', 16);
            $table->decimal('actual_cost', 19, 4);
            $table->decimal('provisional_value', 19, 4);
            $table->decimal('variance_amount', 19, 4);
            $table->json('source_evidence');
            $table->char('source_hash', 64);
            $table->timestamp('created_at', 6)->useCurrent();
            $table->unique(['fg_actual_costing_id','component'], 'uq_fg_actual_costing_component');
            $table->index(['company_id','component'], 'idx_fg_actual_component_company');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fg_actual_costing_components');
        Schema::dropIfExists('fg_actual_costings');
    }
};
