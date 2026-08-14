<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->string('status', 16)->default('OPEN');
            $table->date('deadline')->nullable();
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_rfqs_doc_no');
        });

        DB::statement("ALTER TABLE rfqs ADD CONSTRAINT chk_rfqs_status CHECK (status IN ('OPEN','CLOSED','AWARDED','CANCELLED'))");

        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained('rfqs')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('currency', 3)->default('IDR');
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->string('payment_term')->nullable();
            $table->boolean('is_selected')->default(false);
            $table->timestamps(6);

            $table->index(['rfq_id', 'supplier_id'], 'idx_quotations_rfq_supplier');
        });

        Schema::create('quotation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->restrictOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->foreignId('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('unit_price', 19, 6);
            $table->timestamps(6);

            $table->index('quotation_id', 'idx_quotation_lines');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_lines');
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('rfqs');
    }
};
