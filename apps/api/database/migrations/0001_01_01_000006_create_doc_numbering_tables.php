<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_numbering_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_type', 64);
            $table->string('prefix', 16);
            $table->unsignedTinyInteger('digits')->default(6);
            $table->boolean('reset_yearly')->default(true);   // BR-010: reset per tahun
            $table->timestamps(6);

            $table->unique(['company_id', 'doc_type'], 'uq_numbering_config');
        });

        Schema::create('doc_number_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('prefix', 16);
            $table->unsignedSmallInteger('period_year');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps(6);

            // Counter terpisah per (company, prefix, tahun) — BR-010; concurrency via row lock
            $table->unique(['company_id', 'prefix', 'period_year'], 'uq_number_counters');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_number_counters');
        Schema::dropIfExists('doc_numbering_configs');
    }
};
