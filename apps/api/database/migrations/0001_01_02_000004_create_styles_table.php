<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('styles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('style_no', 64);
            $table->string('buyer_style_ref')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->string('season', 32)->nullable();
            $table->string('category', 16)->default('WOVEN');   // WOVEN/KNIT/OTHER
            $table->string('product_group', 64)->nullable();
            $table->string('lifecycle', 16)->default('DEVELOPMENT'); // DEVELOPMENT/ACTIVE/DISCONTINUED (BR-023)
            $table->text('description')->nullable();
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();

            $table->unique(['company_id', 'style_no'], 'uq_styles_company_styleno');
            $table->index('customer_id', 'idx_styles_customer');
        });

        DB::statement("ALTER TABLE styles ADD CONSTRAINT chk_styles_category CHECK (category IN ('WOVEN','KNIT','OTHER'))");
        DB::statement("ALTER TABLE styles ADD CONSTRAINT chk_styles_lifecycle CHECK (lifecycle IN ('DEVELOPMENT','ACTIVE','DISCONTINUED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('styles');
    }
};
