<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_shade_rules', function (Blueprint $table) {
            $table->id(); $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->boolean('enabled')->default(true); $table->timestamps(6);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unique(['company_id','customer_id'],'uq_customer_shade_rule');
        });
        Schema::create('lays', function (Blueprint $table) {
            $table->id(); $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('cut_order_id')->constrained('cut_orders')->restrictOnDelete();
            $table->string('lay_no',64); $table->unsignedInteger('layer_count'); $table->date('lay_date');
            $table->boolean('shade_validation_enabled')->default(true);
            $table->foreignId('shade_group_id')->nullable()->constrained('shade_groups')->restrictOnDelete();
            $table->string('status',16)->default('DRAFT'); $table->timestamps(6);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unique(['company_id','lay_no'],'uq_lays_no'); $table->index('cut_order_id','idx_lays_cut');
        });
        DB::statement("ALTER TABLE lays ADD CONSTRAINT chk_lays_status CHECK (status IN ('DRAFT','IN_PROGRESS','COMPLETED','CANCELLED'))");
        Schema::create('lay_rolls', function (Blueprint $table) {
            $table->id(); $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('lay_id')->constrained('lays')->restrictOnDelete();
            $table->foreignId('fabric_roll_id')->constrained('fabric_rolls')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('qty_used',18,4); $table->boolean('shade_override')->default(false); $table->timestamps(6);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unique(['lay_id','fabric_roll_id'],'uq_lay_roll'); $table->index('fabric_roll_id','idx_lay_roll_source');
        });
        Schema::create('shade_override_requests', function (Blueprint $table) {
            $table->id(); $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('lay_id')->constrained('lays')->restrictOnDelete();
            $table->foreignId('fabric_roll_id')->constrained('fabric_rolls')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('uoms')->restrictOnDelete(); $table->decimal('qty_used',18,4);
            $table->text('reason'); $table->string('status',16)->default('PENDING');
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('users')->restrictOnDelete(); $table->timestamp('applied_at',6)->nullable();
            $table->timestamps(6); $table->index(['lay_id','status'],'idx_shade_override_lay');
        });
        DB::statement("ALTER TABLE shade_override_requests ADD CONSTRAINT chk_shade_override_status CHECK (status IN ('PENDING','APPLIED','CANCELLED'))");
        Schema::create('cut_outputs', function (Blueprint $table) {
            $table->id(); $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('lay_id')->constrained('lays')->restrictOnDelete();
            $table->foreignId('cut_order_line_id')->constrained('cut_order_lines')->restrictOnDelete();
            $table->decimal('qty_cut',18,4); $table->timestamps(6);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unique(['lay_id','cut_order_line_id'],'uq_cut_output_lay_line'); $table->index('cut_order_line_id','idx_cut_output_line');
        });
        Schema::table('bundles', function (Blueprint $table) {
            $table->foreignId('cut_output_id')->nullable()->after('cut_order_line_id')->constrained('cut_outputs')->restrictOnDelete();
            $table->index('cut_output_id','idx_bundles_cut_output');
        });
    }
    public function down(): void
    {
        Schema::table('bundles', function (Blueprint $table) { $table->dropForeign(['cut_output_id']); $table->dropIndex('idx_bundles_cut_output'); $table->dropColumn('cut_output_id'); });
        Schema::dropIfExists('cut_outputs'); Schema::dropIfExists('shade_override_requests'); Schema::dropIfExists('lay_rolls'); Schema::dropIfExists('lays'); Schema::dropIfExists('customer_shade_rules');
    }
};
