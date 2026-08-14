<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('style_specs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('style_id')->constrained('styles')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->text('description')->nullable();
            $table->text('construction_notes')->nullable();
            $table->timestamps(6);

            $table->unique(['style_id', 'version'], 'uq_style_specs');
        });

        Schema::create('measurement_charts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('style_id')->constrained('styles')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps(6);

            $table->unique(['style_id', 'version'], 'uq_measurement_charts');
        });

        Schema::create('measurement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chart_id')->constrained('measurement_charts')->restrictOnDelete();
            $table->string('pom_code', 32);          // point of measure
            $table->foreignId('size_id')->constrained('sizes')->restrictOnDelete();
            $table->decimal('value', 10, 3);
            $table->decimal('tolerance', 10, 3)->nullable();
            $table->timestamps(6);

            $table->unique(['chart_id', 'pom_code', 'size_id'], 'uq_measurement_lines');
        });

        Schema::create('tech_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('style_id')->constrained('styles')->restrictOnDelete();
            $table->string('file_path');   // S3/MinIO
            $table->string('file_name');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();

            $table->index('style_id', 'idx_tech_packs_style');
        });

        // Sample cycle: PROTO → FIT → PP → TOP, approval buyer per stage
        Schema::create('samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('style_id')->constrained('styles')->restrictOnDelete();
            $table->string('stage', 8);
            $table->unsignedInteger('version')->default(1);
            $table->string('buyer_status', 16)->default('PENDING');
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_samples_doc_no');
            $table->index(['style_id', 'stage'], 'idx_samples_style_stage');
        });

        DB::statement("ALTER TABLE samples ADD CONSTRAINT chk_samples_stage CHECK (stage IN ('PROTO','FIT','PP','TOP'))");
        DB::statement("ALTER TABLE samples ADD CONSTRAINT chk_samples_buyer_status CHECK (buyer_status IN ('PENDING','APPROVED','REJECTED','COMMENTED'))");

        Schema::create('sample_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sample_id')->constrained('samples')->restrictOnDelete();
            $table->string('status', 16);   // APPROVED/REJECTED/COMMENTED
            $table->text('comment')->nullable();
            $table->string('by_name')->nullable();   // pihak buyer (eksternal)
            $table->timestamps(6);

            $table->index('sample_id', 'idx_sample_approvals');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sample_approvals');
        Schema::dropIfExists('samples');
        Schema::dropIfExists('tech_packs');
        Schema::dropIfExists('measurement_lines');
        Schema::dropIfExists('measurement_charts');
        Schema::dropIfExists('style_specs');
    }
};
