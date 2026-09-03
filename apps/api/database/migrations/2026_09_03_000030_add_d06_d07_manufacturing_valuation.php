<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('valuation_allocation_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 64);
            $table->unsignedInteger('version');
            $table->date('effective_date');
            $table->string('status', 16)->default('DRAFT');
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps(6);
            $table->unique(['company_id', 'code', 'version'], 'uq_val_allocation_profile_version');
            $table->index(['company_id', 'status', 'effective_date'], 'idx_val_allocation_profile_effective');
        });

        Schema::create('valuation_allocation_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('profile_id')->constrained('valuation_allocation_profiles')->restrictOnDelete();
            $table->string('component', 16);
            $table->string('stage', 32);
            $table->string('allocation_rule', 32);
            $table->decimal('allocation_value', 18, 8);
            $table->string('allocation_mode', 16)->default('CUMULATIVE');
            $table->json('source_structure');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->unique(['profile_id', 'component', 'stage'], 'uq_val_allocation_rule_component_stage');
            $table->index(['company_id', 'profile_id'], 'idx_val_allocation_rule_company');
        });

        Schema::create('mo_valuation_eligibilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('allocation_profile_id')->constrained('valuation_allocation_profiles')->restrictOnDelete();
            $table->string('policy_version', 32);
            $table->char('standard_snapshot_hash', 64);
            $table->json('allocation_snapshot');
            $table->char('allocation_snapshot_hash', 64);
            $table->date('effective_date');
            $table->string('status', 16)->default('PENDING');
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['company_id', 'production_order_id'], 'uq_mo_valuation_eligibility');
            $table->index(['company_id', 'status', 'effective_date'], 'idx_mo_val_eligibility_status');
        });

        Schema::create('wip_valuation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('eligibility_id')->constrained('mo_valuation_eligibilities')->restrictOnDelete();
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->string('boundary', 32);
            $table->string('valuation_stage', 16);
            $table->string('measure_key', 32);
            $table->string('event_kind', 16);
            $table->string('component', 16);
            $table->decimal('quantity_delta', 18, 4);
            $table->decimal('unit_basis', 19, 6);
            $table->decimal('provisional_value', 19, 4);
            $table->char('standard_snapshot_hash', 64);
            $table->char('allocation_snapshot_hash', 64);
            $table->char('payload_hash', 64);
            $table->timestamp('event_at', 6);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->unique(['company_id', 'source_type', 'source_id', 'valuation_stage', 'component'], 'uq_wip_val_source_stage_component');
            $table->index(['company_id', 'production_order_id', 'valuation_stage'], 'idx_wip_val_mo_stage');
        });

        Schema::create('fg_valuation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('eligibility_id')->constrained('mo_valuation_eligibilities')->restrictOnDelete();
            $table->foreignId('stock_movement_id')->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('packing_list_id')->constrained('packing_lists')->restrictOnDelete();
            $table->string('component', 16);
            $table->decimal('receipt_quantity', 18, 4);
            $table->decimal('cumulative_quantity', 18, 4);
            $table->decimal('unit_basis', 19, 6);
            $table->decimal('provisional_value', 19, 4);
            $table->char('standard_snapshot_hash', 64);
            $table->char('wip_lineage_hash', 64);
            $table->char('payload_hash', 64);
            $table->timestamp('event_at', 6);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->unique(['company_id', 'stock_movement_id', 'component'], 'uq_fg_val_receipt_component');
            $table->index(['company_id', 'production_order_id', 'event_at'], 'idx_fg_val_mo_event');
        });

        Schema::create('actual_cost_freezes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('eligibility_id')->constrained('mo_valuation_eligibilities')->restrictOnDelete();
            $table->unsignedInteger('freeze_version');
            $table->string('status', 16)->default('PENDING');
            $table->string('period', 7);
            $table->char('standard_snapshot_hash', 64);
            $table->decimal('denominator_quantity', 18, 4);
            $table->json('component_amounts');
            $table->json('source_evidence');
            $table->char('calculation_hash', 64);
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('frozen_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('frozen_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['company_id', 'production_order_id', 'freeze_version'], 'uq_actual_cost_freeze_version');
            $table->index(['company_id', 'status', 'period'], 'idx_actual_cost_freeze_status');
        });

        Schema::create('valuation_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('actual_cost_freeze_id')->constrained('actual_cost_freezes')->restrictOnDelete();
            $table->unsignedInteger('freeze_version');
            $table->string('valuation_object', 24);
            $table->string('component', 16);
            $table->decimal('affected_quantity', 18, 4);
            $table->decimal('provisional_value', 19, 4);
            $table->decimal('actual_value', 19, 4);
            $table->decimal('variance_amount', 19, 4);
            $table->date('event_date');
            $table->string('currency', 3);
            $table->string('source_identity', 191);
            $table->char('payload_hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->unique(['company_id', 'production_order_id', 'freeze_version', 'valuation_object', 'component'], 'uq_val_adjustment_identity');
            $table->index(['company_id', 'event_date', 'valuation_object'], 'idx_val_adjustment_event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('valuation_adjustments');
        Schema::dropIfExists('actual_cost_freezes');
        Schema::dropIfExists('fg_valuation_events');
        Schema::dropIfExists('wip_valuation_events');
        Schema::dropIfExists('mo_valuation_eligibilities');
        Schema::dropIfExists('valuation_allocation_rules');
        Schema::dropIfExists('valuation_allocation_profiles');
    }
};
