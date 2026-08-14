<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('type', 16);              // FABRIC / TRIM / PACKAGING
            $table->string('material_class', 32)->nullable(); // untuk flag backflush (BR-041)
            // Kolom khusus fabric (BR-002/003)
            $table->string('composition')->nullable();        // mis. 100% cotton
            $table->string('construction')->nullable();       // mis. poplin, single jersey
            $table->decimal('gsm', 10, 2)->nullable();
            $table->decimal('width_cm', 10, 2)->nullable();
            $table->decimal('shrinkage_std_pct', 7, 4)->nullable();
            // UOM beli & pakai (BR-002 dual UOM)
            $table->foreignId('buy_uom_id')->nullable()->constrained('uoms')->restrictOnDelete();
            $table->foreignId('use_uom_id')->nullable()->constrained('uoms')->restrictOnDelete();
            // BR-003: fabric = roll-level; trim = lot-level
            $table->string('tracking_level', 8)->default('LOT'); // ROLL / LOT
            // BR-043: safety stock (masuk netting MRP)
            $table->decimal('safety_stock_qty', 18, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();

            $table->unique(['company_id', 'code'], 'uq_materials_company_code');
            $table->index('type', 'idx_materials_type');
        });

        DB::statement("ALTER TABLE materials ADD CONSTRAINT chk_materials_type CHECK (type IN ('FABRIC','TRIM','PACKAGING'))");
        DB::statement("ALTER TABLE materials ADD CONSTRAINT chk_materials_tracking CHECK (tracking_level IN ('ROLL','LOT'))");

        // Konversi default per material (override per roll saat GR — BR-002)
        Schema::create('material_uom_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('rate_to_use_uom', 18, 6);
            $table->timestamps(6);

            $table->unique(['material_id', 'uom_id'], 'uq_material_uom_conv');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_uom_conversions');
        Schema::dropIfExists('materials');
    }
};
