<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shade_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name')->nullable();
            $table->timestamps(6);

            $table->unique(['company_id', 'code'], 'uq_shade_groups_code');
        });

        Schema::create('colors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('buyer_color_name')->nullable();
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();

            $table->unique(['company_id', 'code'], 'uq_colors_company_code');
        });

        Schema::create('colorways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('style_id')->constrained('styles')->restrictOnDelete();
            $table->foreignId('color_id')->constrained('colors')->restrictOnDelete();
            $table->string('lab_dip_ref')->nullable();
            $table->foreignId('shade_group_id')->nullable()->constrained('shade_groups')->restrictOnDelete(); // BR-053
            $table->timestamps(6);

            $table->unique(['style_id', 'color_id'], 'uq_colorways_style_color');
            $table->index('shade_group_id', 'idx_colorways_shade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colorways');
        Schema::dropIfExists('colors');
        Schema::dropIfExists('shade_groups');
    }
};
