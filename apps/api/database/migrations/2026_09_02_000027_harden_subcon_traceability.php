<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subcon_orders', function (Blueprint $table) {
            $table->string('client_reference', 64)->nullable()->after('doc_no');
            $table->unique(['company_id', 'client_reference'], 'uq_subcon_client_reference');
        });

        Schema::table('subcon_fees', function (Blueprint $table) {
            $table->foreignId('subcon_order_line_id')->nullable()->after('subcon_order_id')->constrained('subcon_order_lines')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->after('subcon_order_line_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('receipt_reference', 64)->nullable()->after('warehouse_id');
            $table->unique(['subcon_order_id', 'receipt_reference'], 'uq_subcon_receipt_reference');
            $table->index('subcon_order_line_id', 'idx_subcon_fee_line');
        });
    }

    public function down(): void
    {
        Schema::table('subcon_fees', function (Blueprint $table) {
            $table->dropIndex('idx_subcon_fee_line');
            $table->dropUnique('uq_subcon_receipt_reference');
            $table->dropConstrainedForeignId('warehouse_id');
            $table->dropConstrainedForeignId('subcon_order_line_id');
            $table->dropColumn('receipt_reference');
        });

        Schema::table('subcon_orders', function (Blueprint $table) {
            $table->dropUnique('uq_subcon_client_reference');
            $table->dropColumn('client_reference');
        });
    }
};
