<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // BR-101: full GL internal
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('type', 16);          // ASSET/LIABILITY/EQUITY/REVENUE/EXPENSE
            $table->string('normal_balance', 6); // DEBIT/CREDIT
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();

            $table->unique(['company_id', 'code'], 'uq_coa_company_code');
            $table->foreign('parent_id', 'fk_coa_parent')->references('id')->on('chart_of_accounts')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE chart_of_accounts ADD CONSTRAINT chk_coa_type CHECK (type IN ('ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE'))");
        DB::statement("ALTER TABLE chart_of_accounts ADD CONSTRAINT chk_coa_balance CHECK (normal_balance IN ('DEBIT','CREDIT'))");

        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 3);   // IDR, USD, ...
            $table->string('name');
            $table->string('symbol', 8)->nullable();
            $table->timestamps(6);

            $table->unique(['company_id', 'code'], 'uq_currencies_code');
        });

        // BR-102: rate per currency per tanggal
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->date('rate_date');
            $table->decimal('rate', 18, 6);   // terhadap base currency company
            $table->timestamps(6);

            $table->unique(['currency_id', 'rate_date'], 'uq_exchange_rates');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('chart_of_accounts');
    }
};
