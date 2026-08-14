<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('nik', 32);                    // Nomor induk karyawan
            $table->string('name');
            $table->string('section', 64)->nullable();    // cutting/sewing/finishing/...
            $table->unsignedBigInteger('line_id')->nullable(); // FK ke lines (ditambah setelah tabel lines ada)
            $table->string('skill')->nullable();
            $table->boolean('is_operator')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();

            $table->unique(['company_id', 'nik'], 'uq_employees_company_nik');
            $table->index('line_id', 'idx_employees_line');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
