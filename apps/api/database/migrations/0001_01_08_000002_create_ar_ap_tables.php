<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AR: invoice penjualan dari shipment (BR-102: kurs tersimpan)
        Schema::create('ar_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->restrictOnDelete();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 18, 6)->default(1);   // BR-102
            $table->decimal('total_amount', 19, 4);
            $table->decimal('paid_amount', 19, 4)->default(0);
            $table->string('status', 16)->default('OPEN');
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_ar_invoices_doc_no');
            $table->index(['company_id', 'customer_id', 'status'], 'idx_ar_invoices_customer');
        });

        DB::statement("ALTER TABLE ar_invoices ADD CONSTRAINT chk_ar_invoices_status CHECK (status IN ('OPEN','PARTIAL','PAID','VOID'))");

        Schema::create('ar_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ar_invoice_id')->constrained('ar_invoices')->restrictOnDelete();
            $table->foreignId('style_id')->nullable()->constrained('styles')->restrictOnDelete();
            $table->string('description');
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_price', 19, 6);
            $table->decimal('amount', 19, 4);
            $table->timestamps(6);

            $table->index('ar_invoice_id', 'idx_ar_invoice_lines');
        });

        Schema::create('ar_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('ar_invoice_id')->constrained('ar_invoices')->restrictOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 19, 4);
            $table->string('method', 32)->nullable();   // transfer/giro/...
            $table->string('reference_no')->nullable();
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_ar_payments_doc_no');
            $table->index('ar_invoice_id', 'idx_ar_payments_invoice');
        });

        // AP: pembayaran ke supplier terhadap supplier_invoices (hanya MATCHED — BR-050)
        Schema::create('ap_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('supplier_invoice_id')->constrained('supplier_invoices')->restrictOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 19, 4);
            $table->string('method', 32)->nullable();
            $table->string('reference_no')->nullable();
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_ap_payments_doc_no');
            $table->index('supplier_invoice_id', 'idx_ap_payments_invoice');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ap_payments');
        Schema::dropIfExists('ar_payments');
        Schema::dropIfExists('ar_invoice_lines');
        Schema::dropIfExists('ar_invoices');
    }
};
