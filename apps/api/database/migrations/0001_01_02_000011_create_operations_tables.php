<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');                    // mis. jahit sisi, pasang lengan
            $table->string('machine_type', 64)->nullable();
            $table->string('grade', 8)->nullable();    // grade operator (A/B/C)
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();

            $table->unique(['company_id', 'code'], 'uq_operations_company_code');
        });

        // BR-033: SMV/SAM versioned per operation
        Schema::create('operation_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_id')->constrained('operations')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->decimal('smv', 10, 4);            // Standard Minute Value
            $table->date('valid_from');
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();

            $table->unique(['operation_id', 'version'], 'uq_operation_versions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_versions');
        Schema::dropIfExists('operations');
    }
};
