<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('containers', function (Blueprint $table) {
            $table->id(); $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->string('container_no', 64); $table->string('size', 32)->nullable(); $table->string('seal_no', 64)->nullable();
            $table->timestamps(6); $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete(); $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unique(['company_id','container_no'], 'uq_containers_company_no'); $table->index('shipment_id', 'idx_containers_shipment');
        });
        Schema::create('commercial_invoices', function (Blueprint $table) {
            $table->id(); $table->foreignId('company_id')->constrained('companies')->restrictOnDelete(); $table->string('doc_no', 32);
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete(); $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->date('invoice_date'); $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete(); $table->decimal('exchange_rate',24,12);
            $table->string('lc_number', 128)->nullable(); $table->decimal('total_amount',19,4); $table->string('status',16)->default('DRAFT');
            $table->timestamps(6); $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete(); $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unique(['company_id','doc_no'], 'uq_commercial_invoices_doc'); $table->unique('shipment_id', 'uq_commercial_invoices_shipment'); $table->index(['company_id','status'], 'idx_commercial_invoices_status');
        });
        Schema::create('commercial_invoice_lines', function (Blueprint $table) {
            $table->id(); $table->foreignId('commercial_invoice_id')->constrained('commercial_invoices')->restrictOnDelete();
            $table->foreignId('shipment_line_id')->constrained('shipment_lines')->restrictOnDelete(); $table->foreignId('style_id')->constrained('styles')->restrictOnDelete();
            $table->foreignId('colorway_id')->constrained('colorways')->restrictOnDelete(); $table->foreignId('size_id')->constrained('sizes')->restrictOnDelete();
            $table->decimal('qty',18,4); $table->decimal('unit_price',19,6); $table->decimal('amount',19,4); $table->timestamps(6);
            $table->unique('shipment_line_id', 'uq_ci_lines_shipment_line'); $table->index('commercial_invoice_id', 'idx_ci_lines_invoice');
        });
        Schema::create('export_documents', function (Blueprint $table) {
            $table->id(); $table->foreignId('company_id')->constrained('companies')->restrictOnDelete(); $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->string('document_type',32); $table->string('reference_no',128)->nullable(); $table->date('issue_date')->nullable(); $table->string('file_reference',2048)->nullable();
            $table->string('status',16)->default('DRAFT'); $table->timestamps(6); $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete(); $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['company_id','shipment_id','document_type'], 'idx_export_documents_shipment');
        });
        DB::statement("ALTER TABLE commercial_invoices ADD CONSTRAINT chk_commercial_invoices_status CHECK (status IN ('DRAFT','ISSUED','CANCELLED'))");
        DB::statement('ALTER TABLE commercial_invoices ADD CONSTRAINT chk_commercial_invoices_total CHECK (total_amount > 0)');
        DB::statement("ALTER TABLE export_documents ADD CONSTRAINT chk_export_documents_type CHECK (document_type IN ('COO','BILL_OF_LADING','LC_DOCUMENT','CUSTOMS','OTHER'))");
        DB::statement("ALTER TABLE export_documents ADD CONSTRAINT chk_export_documents_status CHECK (status IN ('DRAFT','ISSUED','CANCELLED'))");
        Schema::table('ar_invoices', function (Blueprint $table) { $table->foreignId('commercial_invoice_id')->nullable()->after('shipment_id')->constrained('commercial_invoices')->restrictOnDelete(); $table->unique('commercial_invoice_id','uq_ar_invoices_commercial_invoice'); });
        $now=now(); foreach(DB::table('companies')->pluck('id') as $companyId) DB::table('doc_numbering_configs')->insertOrIgnore(['company_id'=>$companyId,'doc_type'=>'CI','prefix'=>'CI','digits'=>6,'reset_yearly'=>true,'created_at'=>$now,'updated_at'=>$now]);
    }
    public function down(): void
    {
        Schema::table('ar_invoices', function (Blueprint $table) { $table->dropUnique('uq_ar_invoices_commercial_invoice'); $table->dropForeign(['commercial_invoice_id']); $table->dropColumn('commercial_invoice_id'); });
        Schema::dropIfExists('export_documents'); Schema::dropIfExists('commercial_invoice_lines'); Schema::dropIfExists('commercial_invoices'); Schema::dropIfExists('containers');
        DB::table('doc_numbering_configs')->where('doc_type','CI')->where('prefix','CI')->delete();
    }
};
