<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('factory_id')->nullable()->constrained('factories')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('section', 32)->default('SEWING');
            $table->unsignedInteger('capacity_std')->nullable();   // pcs/hari standar
            $table->unsignedInteger('manpower_std')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();

            $table->unique(['company_id', 'code'], 'uq_lines_company_code');
        });

        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('line_id')->nullable()->constrained('lines')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('type', 64);   // single needle, overlock, bartack, ...
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();

            $table->unique(['company_id', 'code'], 'uq_machines_company_code');
            $table->index('line_id', 'idx_machines_line');
        });

        // FK employees.line_id → lines (ditambahkan setelah lines ada)
        Schema::table('employees', function (Blueprint $table) {
            $table->foreign('line_id', 'fk_employees_line')->references('id')->on('lines')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign('fk_employees_line');
        });
        Schema::dropIfExists('machines');
        Schema::dropIfExists('lines');
    }
};
