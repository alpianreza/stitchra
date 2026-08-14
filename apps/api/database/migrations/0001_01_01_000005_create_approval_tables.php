<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_type', 64);
            $table->unsignedInteger('version')->default(1);
            $table->string('mode', 16)->default('sequential');   // sequential | parallel (BR-015)
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['company_id', 'doc_type', 'version'], 'uq_approval_flows');
        });

        Schema::create('approval_flow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained('approval_flows')->restrictOnDelete();
            $table->unsignedInteger('step_no');
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->decimal('min_value', 19, 4)->nullable();    // threshold dari matrix, bukan kode (BR-015)
            $table->decimal('max_value', 19, 4)->nullable();
            $table->timestamps(6);

            $table->index(['flow_id', 'step_no'], 'idx_flow_steps');
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('flow_id')->constrained('approval_flows')->restrictOnDelete();
            $table->string('doc_type', 64);
            $table->unsignedBigInteger('doc_id');
            $table->string('status', 16)->default('PENDING');
            $table->unsignedInteger('current_step')->default(1);
            $table->boolean('is_active')->default(true);
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at', 6);
            $table->timestamp('completed_at', 6)->nullable();
            $table->timestamps(6);

            $table->index(['company_id', 'status'], 'idx_approval_requests_status');
            $table->index(['doc_type', 'doc_id'], 'idx_approval_requests_doc');
        });

        // Status terkontrol (VARCHAR + CHECK — portabel MySQL/PostgreSQL, DEC-03)
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT chk_approval_requests_status CHECK (status IN ('PENDING','APPROVED','REJECTED','REVISION','CANCELLED'))");

        Schema::create('approval_step_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('approval_requests')->restrictOnDelete();
            $table->unsignedInteger('step_no');
            $table->foreignId('approver_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('delegated_from')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('decision', 16);   // APPROVED / REJECTED / REVISION
            $table->text('note')->nullable();
            $table->timestamp('decided_at', 6);
            $table->timestamps(6);

            $table->index(['request_id', 'step_no'], 'idx_step_instances');
        });

        DB::statement("ALTER TABLE approval_step_instances ADD CONSTRAINT chk_step_decision CHECK (decision IN ('APPROVED','REJECTED','REVISION'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_step_instances');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_flow_steps');
        Schema::dropIfExists('approval_flows');
    }
};
