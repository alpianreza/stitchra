<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('currency', 3)->default('IDR');
            $table->string('payment_term')->nullable();
            $table->string('incoterm', 8)->nullable();          // FOB, CIF, EXW...
            $table->decimal('shipment_tolerance_pct', 7, 4)->nullable(); // BR-021
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();

            $table->unique(['company_id', 'code'], 'uq_customers_company_code');
        });

        Schema::create('customer_aql_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('inspection_level', 8)->default('G2');   // ISO 2859-1 General Level II
            $table->decimal('aql_critical', 5, 2)->default(0);       // critical = 0 (BR-008)
            $table->decimal('aql_major', 5, 2)->default(2.5);
            $table->decimal('aql_minor', 5, 2)->default(4.0);
            $table->string('report_format')->nullable();             // template report per buyer
            $table->timestamps(6);

            $table->unique(['company_id', 'customer_id'], 'uq_customer_aql');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_aql_configs');
        Schema::dropIfExists('customers');
    }
};
