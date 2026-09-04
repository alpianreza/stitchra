<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('base_currency', 3)->default('USD')->change();
        });

        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->decimal('rate', 24, 12)->change();
        });
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->decimal('exchange_rate', 24, 12)->nullable()->change();
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->decimal('exchange_rate', 24, 12)->nullable()->change();
        });
        foreach (['ar_invoices', 'supplier_invoices', 'ar_payments', 'ap_payments'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->decimal('exchange_rate', 24, 12)->default(1)->change();
            });
        }

        // Mengganti base currency setelah jurnal/AR/AP terbentuk akan merusak makna
        // historical base amount. Karena itu hanya company tanpa data finance yang
        // dikonversi otomatis; company berisi transaksi wajib menjalani cutover terkontrol.
        $financialTables = ['journals', 'ar_invoices', 'supplier_invoices', 'ar_payments', 'ap_payments'];
        foreach (DB::table('companies')->where('base_currency', 'IDR')->pluck('id') as $companyId) {
            $hasFinancialHistory = false;
            foreach ($financialTables as $tableName) {
                if (Schema::hasTable($tableName) && DB::table($tableName)->where('company_id', $companyId)->exists()) {
                    $hasFinancialHistory = true;
                    break;
                }
            }
            if (! $hasFinancialHistory) {
                DB::table('companies')->where('id', $companyId)->update(['base_currency' => 'USD']);
            }
        }
    }

    public function down(): void
    {
        foreach (['ar_invoices', 'supplier_invoices', 'ar_payments', 'ap_payments'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->decimal('exchange_rate', 18, 6)->default(1)->change();
            });
        }
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->decimal('exchange_rate', 18, 6)->nullable()->change();
        });
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->decimal('exchange_rate', 18, 6)->nullable()->change();
        });
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->decimal('rate', 18, 6)->change();
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->string('base_currency', 3)->default('IDR')->change();
        });
    }
};
