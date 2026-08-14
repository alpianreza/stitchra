<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // core.integration — job import/export (CSV/Excel)
        Schema::create('integration_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('type', 32);          // MASTER_IMPORT / EXPORT / ...
            $table->string('entity', 64);        // slug registry (customers, materials, ...)
            $table->string('file_path');         // S3/MinIO path
            $table->string('status', 16)->default('PENDING');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('success_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->json('errors')->nullable();  // error per baris
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();

            $table->index(['company_id', 'type', 'status'], 'idx_integration_jobs');
        });

        DB::statement("ALTER TABLE integration_jobs ADD CONSTRAINT chk_integration_status CHECK (status IN ('PENDING','PROCESSING','DONE','FAILED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_jobs');
    }
};
