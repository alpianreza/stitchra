<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('factory_id')->nullable()->constrained('factories')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('type', 16);   // RM / WIP / FG / TRIM / SUBCON_VIRTUAL (BR-090)
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();

            $table->unique(['company_id', 'code'], 'uq_warehouses_company_code');
            $table->index('type', 'idx_warehouses_type');
        });

        DB::statement("ALTER TABLE warehouses ADD CONSTRAINT chk_warehouses_type CHECK (type IN ('RM','WIP','FG','TRIM','SUBCON_VIRTUAL'))");

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name')->nullable();
            $table->timestamps(6);

            $table->unique(['warehouse_id', 'code'], 'uq_locations_wh_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
        Schema::dropIfExists('warehouses');
    }
};
