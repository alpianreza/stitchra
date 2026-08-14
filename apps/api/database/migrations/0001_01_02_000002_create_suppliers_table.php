<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('type', 16);   // FABRIC/TRIM/PACKAGING/SUBCON
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->string('currency', 3)->default('IDR');
            $table->string('payment_term')->nullable();
            $table->text('address')->nullable();
            $table->string('contact')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();

            $table->unique(['company_id', 'code'], 'uq_suppliers_company_code');
            $table->index('type', 'idx_suppliers_type');
        });

        DB::statement("ALTER TABLE suppliers ADD CONSTRAINT chk_suppliers_type CHECK (type IN ('FABRIC','TRIM','PACKAGING','SUBCON'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
