<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('buyer_po_no')->nullable();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 18, 6)->nullable();   // BR-102
            $table->date('order_date');
            $table->date('ex_factory_date')->nullable();
            $table->decimal('tolerance_pct', 7, 4)->nullable();    // override buyer (BR-021)
            $table->string('status', 16)->default('DRAFT');
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();

            $table->unique(['company_id', 'doc_no'], 'uq_sales_orders_doc_no');
            $table->unique(['customer_id', 'buyer_po_no'], 'uq_sales_orders_buyerpo');
            $table->index(['company_id', 'status'], 'idx_sales_orders_status');
        });

        // Status baseline dokumen (BR-012) — VARCHAR+CHECK portabel
        DB::statement("ALTER TABLE sales_orders ADD CONSTRAINT chk_sales_orders_status CHECK (status IN ('DRAFT','SUBMITTED','APPROVED','CONFIRMED','IN_PROGRESS','CLOSED','REJECTED','CANCELLED'))");

        // BR-020: matrix line — satu baris per style×colorway×size (bukan JSON)
        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('style_id')->constrained('styles')->restrictOnDelete();
            $table->foreignId('colorway_id')->constrained('colorways')->restrictOnDelete();
            $table->foreignId('size_id')->constrained('sizes')->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->decimal('price', 19, 4);
            $table->timestamps(6);

            $table->unique(['sales_order_id', 'style_id', 'colorway_id', 'size_id'], 'uq_so_lines_matrix');
        });

        // BR-022: amendment — terkunci bila cutting sudah mulai (dicek di service)
        Schema::create('order_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->json('line_delta');   // perubahan qty/rasio per line
            $table->text('reason');
            $table->string('status', 16)->default('DRAFT');
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_order_amendments_doc_no');
            $table->index('sales_order_id', 'idx_amendments_so');
        });

        DB::statement("ALTER TABLE order_amendments ADD CONSTRAINT chk_amendments_status CHECK (status IN ('DRAFT','SUBMITTED','APPROVED','REJECTED','CANCELLED'))");

        Schema::create('delivery_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->date('delivery_date');
            $table->decimal('qty', 18, 4);
            $table->string('destination')->nullable();
            $table->timestamps(6);

            $table->index('sales_order_id', 'idx_delivery_schedules_so');
        });

        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('doc_no', 32);
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('title');
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('OPEN');
            $table->timestamps(6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['company_id', 'doc_no'], 'uq_inquiries_doc_no');
        });

        DB::statement("ALTER TABLE inquiries ADD CONSTRAINT chk_inquiries_status CHECK (status IN ('OPEN','QUOTED','WON','LOST','CANCELLED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
        Schema::dropIfExists('delivery_schedules');
        Schema::dropIfExists('order_amendments');
        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');
    }
};
