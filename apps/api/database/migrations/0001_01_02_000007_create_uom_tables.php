<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uoms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 16);   // PCS, MTR, YDS, KG, CONE, GRS
            $table->string('name');
            $table->timestamps(6);

            $table->unique(['company_id', 'code'], 'uq_uoms_company_code');
        });

        // BR-002: konversi antar UOM per material (konversi kain final tersimpan per roll di GR)
        Schema::create('uom_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('material_id');
            $table->foreignId('from_uom_id')->constrained('uoms')->restrictOnDelete();
            $table->foreignId('to_uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('rate', 18, 6);   // 1 from = rate to
            $table->timestamps(6);

            $table->unique(['material_id', 'from_uom_id', 'to_uom_id'], 'uq_uom_conv');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uom_conversions');
        Schema::dropIfExists('uoms');
    }
};
