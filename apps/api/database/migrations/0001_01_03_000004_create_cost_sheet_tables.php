<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // BR-100: cost sheet estimated per style; APPROVED = standard cost
        Schema::create('cost_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('style_id')->constrained('styles')->restrictOnDelete();
            $table->foreignId('bom_version_id')->nullable()->constrained('bom_versions')->restrictOnDelete();
            $table->foreignId('routing_version_id')->nullable()->constrained('routing_versions')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->decimal('fabric_cost', 19, 4)->default(0);
            $table->decimal('trim_cost', 19, 4)->default(0);
            $table->decimal('cm_cost', 19, 4)->default(0);        // SAM × cost/min
            $table->decimal('overhead_cost', 19, 4)->default(0);  // SAM × OH rate
            $table->decimal('subcon_cost', 19, 4)->default(0);
            $table->decimal('other_cost', 19, 4)->default(0);
            $table->decimal('fob_price', 19, 4)->default(0);
            $table->decimal('margin_pct', 7, 4)->default(0);
            $table->string('status', 16)->default('DRAFT');
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_cost_sheets_doc_no');
            $table->index('style_id', 'idx_cost_sheets_style');
        });

        DB::statement("ALTER TABLE cost_sheets ADD CONSTRAINT chk_cost_sheets_status CHECK (status IN ('DRAFT','SUBMITTED','APPROVED','OBSOLETE'))");

        Schema::create('cost_sheet_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cost_sheet_id')->constrained('cost_sheets')->restrictOnDelete();
            $table->string('component_type', 16);   // FABRIC/TRIM/CM/OVERHEAD/SUBCON/OTHER
            $table->string('description');
            $table->decimal('qty', 18, 6)->nullable();
            $table->decimal('rate', 19, 6)->nullable();
            $table->decimal('amount', 19, 4);
            $table->timestamps(6);

            $table->index('cost_sheet_id', 'idx_cost_sheet_lines');
        });

        DB::statement("ALTER TABLE cost_sheet_lines ADD CONSTRAINT chk_cost_line_type CHECK (component_type IN ('FABRIC','TRIM','CM','OVERHEAD','SUBCON','OTHER'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_sheet_lines');
        Schema::dropIfExists('cost_sheets');
    }
};
