<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_scans', function (Blueprint $table): void {
            // Snapshot only for new transactions. Historical scans remain nullable; no backfill decision exists.
            $table->decimal('qty', 18, 4)->nullable()->after('stage');
        });

        Schema::create('wip_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('bundle_id')->constrained('bundles')->restrictOnDelete();
            $table->foreignId('source_scan_id')->constrained('production_scans')->restrictOnDelete();
            $table->string('from_stage', 16);
            $table->string('to_stage', 16);
            $table->decimal('qty', 18, 4);
            $table->timestamp('transferred_at', 6);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', 6)->useCurrent();

            $table->unique('source_scan_id', 'uq_wip_transfer_source_scan');
            $table->unique(['bundle_id','from_stage','to_stage'], 'uq_wip_transfer_bundle_stage');
            $table->index(['company_id','production_order_id','to_stage'], 'idx_wip_transfer_mo_stage');
        });

        DB::statement("ALTER TABLE wip_transfers ADD CONSTRAINT chk_wip_transfer_stages CHECK (from_stage IN ('CUTTING','SEWING') AND to_stage IN ('SEWING','FINISHING') AND from_stage <> to_stage)");
        DB::statement('ALTER TABLE wip_transfers ADD CONSTRAINT chk_wip_transfer_qty CHECK (qty > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('wip_transfers');
        Schema::table('production_scans', fn (Blueprint $table) => $table->dropColumn('qty'));
    }
};
