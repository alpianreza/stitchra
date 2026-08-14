<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // BR-072: defect library — defect tidak boleh free-text
        Schema::create('defect_library', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('category', 16);   // FABRIC/WORKMANSHIP/MEASUREMENT/PACKAGING/OTHER
            $table->string('severity', 8);    // CRITICAL/MAJOR/MINOR
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();

            $table->unique(['company_id', 'code'], 'uq_defect_library_code');
        });

        DB::statement("ALTER TABLE defect_library ADD CONSTRAINT chk_defect_category CHECK (category IN ('FABRIC','WORKMANSHIP','MEASUREMENT','PACKAGING','OTHER'))");
        DB::statement("ALTER TABLE defect_library ADD CONSTRAINT chk_defect_severity CHECK (severity IN ('CRITICAL','MAJOR','MINOR'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('defect_library');
    }
};
