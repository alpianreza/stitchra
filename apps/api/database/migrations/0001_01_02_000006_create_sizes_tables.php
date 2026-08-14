<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 16);          // XS, S, M, L, XL, 28, 30...
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps(6);

            $table->unique(['company_id', 'code'], 'uq_sizes_company_code');
        });

        Schema::create('size_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name')->nullable();
            $table->timestamps(6);

            $table->unique(['company_id', 'code'], 'uq_size_ranges_code');
        });

        Schema::create('size_range_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('size_range_id')->constrained('size_ranges')->restrictOnDelete();
            $table->foreignId('size_id')->constrained('sizes')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps(6);

            $table->unique(['size_range_id', 'size_id'], 'uq_size_range_lines');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('size_range_lines');
        Schema::dropIfExists('size_ranges');
        Schema::dropIfExists('sizes');
    }
};
