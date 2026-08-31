<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_balance_locks', function (Blueprint $table): void {
            $table->char('balance_key', 64)->primary();
            $table->timestamps(6);
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->unique(
                ['company_id', 'movement_type', 'source_document_type', 'source_document_id'],
                'uq_stock_movements_source_type',
            );
        });

        DB::statement('ALTER TABLE stock_ledger DROP CHECK chk_ledger_qty');
        DB::statement("ALTER TABLE stock_ledger ADD CONSTRAINT chk_ledger_qty CHECK ((movement_type = 'QUALITY_RELEASE' AND qty_in = 0 AND qty_out = 0) OR (movement_type <> 'QUALITY_RELEASE' AND ((qty_in > 0 AND qty_out = 0) OR (qty_out > 0 AND qty_in = 0))))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_ledger DROP CHECK chk_ledger_qty');
        DB::statement('ALTER TABLE stock_ledger ADD CONSTRAINT chk_ledger_qty CHECK ((qty_in > 0 AND qty_out = 0) OR (qty_out > 0 AND qty_in = 0))');

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropUnique('uq_stock_movements_source_type');
        });

        Schema::dropIfExists('stock_balance_locks');
    }
};
