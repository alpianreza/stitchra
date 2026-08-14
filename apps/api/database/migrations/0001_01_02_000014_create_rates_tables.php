<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // BR-009: overhead dialokasikan per menit SAM terpakai — rate per company per periode
        Schema::create('overhead_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('period', 7);   // YYYY-MM
            $table->decimal('rate_per_minute', 18, 6);
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['company_id', 'period'], 'uq_overhead_rates');
        });

        // Cost-per-minute per line per periode — untuk CM costing (CMT = SMV × cost/min)
        Schema::create('line_cost_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('line_id')->constrained('lines')->restrictOnDelete();
            $table->string('period', 7);   // YYYY-MM
            $table->decimal('cost_per_minute', 18, 6);
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['line_id', 'period'], 'uq_line_cost_rates');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('line_cost_rates');
        Schema::dropIfExists('overhead_rates');
    }
};
