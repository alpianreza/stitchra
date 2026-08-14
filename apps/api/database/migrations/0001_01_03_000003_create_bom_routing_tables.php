<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // BR-030: BOM versioned — perubahan pasca-approval = versi baru
        Schema::create('boms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('style_id')->constrained('styles')->restrictOnDelete();
            $table->unsignedInteger('current_version')->default(0);
            $table->timestamps(6);

            $table->unique('style_id', 'uq_boms_style');
        });

        Schema::create('bom_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_id')->constrained('boms')->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('status', 16)->default('DRAFT');
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['bom_id', 'version_no'], 'uq_bom_versions');
        });

        DB::statement("ALTER TABLE bom_versions ADD CONSTRAINT chk_bom_versions_status CHECK (status IN ('DRAFT','SUBMITTED','APPROVED','OBSOLETE'))");

        // BR-032: kolom BOM line; BR-031: estimated vs actual terpisah
        Schema::create('bom_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_version_id')->constrained('bom_versions')->restrictOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->foreignId('colorway_id')->nullable()->constrained('colorways')->restrictOnDelete(); // null = semua colorway
            $table->decimal('qty_per_pcs', 18, 6);
            $table->foreignId('uom_id')->constrained('uoms')->restrictOnDelete();   // UOM pakai
            $table->decimal('wastage_pct', 7, 4)->default(0);
            $table->decimal('shrinkage_pct', 7, 4)->default(0);
            $table->decimal('consumption_estimated', 18, 6)->nullable();   // formula sampling
            $table->decimal('consumption_actual', 18, 6)->nullable();      // realisasi marker (diisi Phase 6)
            $table->boolean('is_backflush')->default(false);               // BR-041: trim murah
            $table->timestamps(6);

            $table->index('bom_version_id', 'idx_bom_lines_version');
            $table->index('material_id', 'idx_bom_lines_material');
        });

        // BR-033: Routing versioned + SMV per operasi
        Schema::create('routings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('style_id')->constrained('styles')->restrictOnDelete();
            $table->unsignedInteger('current_version')->default(0);
            $table->timestamps(6);

            $table->unique('style_id', 'uq_routings_style');
        });

        Schema::create('routing_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routing_id')->constrained('routings')->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->decimal('total_sam', 10, 4)->default(0);
            $table->string('status', 16)->default('DRAFT');
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['routing_id', 'version_no'], 'uq_routing_versions');
        });

        DB::statement("ALTER TABLE routing_versions ADD CONSTRAINT chk_routing_versions_status CHECK (status IN ('DRAFT','SUBMITTED','APPROVED','OBSOLETE'))");

        Schema::create('routing_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routing_version_id')->constrained('routing_versions')->restrictOnDelete();
            $table->unsignedInteger('seq');
            $table->foreignId('operation_id')->constrained('operations')->restrictOnDelete();
            $table->decimal('smv', 10, 4);
            $table->string('machine_type', 64)->nullable();
            $table->timestamps(6);

            $table->unique(['routing_version_id', 'seq'], 'uq_routing_operations_seq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routing_operations');
        Schema::dropIfExists('routing_versions');
        Schema::dropIfExists('routings');
        Schema::dropIfExists('bom_lines');
        Schema::dropIfExists('bom_versions');
        Schema::dropIfExists('boms');
    }
};
