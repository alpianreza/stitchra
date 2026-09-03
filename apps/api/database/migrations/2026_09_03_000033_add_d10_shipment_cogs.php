<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up():void
    {
        Schema::create('shipment_cogs',function(Blueprint $table):void{
            $table->id();$table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->foreignId('account_mapping_id')->constrained('account_mappings')->restrictOnDelete();
            $table->foreignId('debit_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('credit_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->string('event',32)->default('SHIPMENT_COGS');$table->decimal('base_cogs_total',19,4);
            $table->string('currency',3);$table->date('posting_date');$table->string('gl_period',7);
            $table->string('status',16)->default('PENDING');$table->char('posting_key',64);$table->char('source_hash',64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();$table->timestamp('created_at',6)->useCurrent();
            $table->unique(['company_id','shipment_id','event'],'uq_shipment_cogs_event');
            $table->unique('posting_key','uq_shipment_cogs_posting_key');$table->unique('source_hash','uq_shipment_cogs_source_hash');
            $table->index(['company_id','gl_period','status'],'idx_shipment_cogs_period');
        });
        Schema::create('shipment_cogs_lines',function(Blueprint $table):void{
            $table->id();$table->foreignId('shipment_cogs_id')->constrained('shipment_cogs')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('shipment_line_id')->constrained('shipment_lines')->restrictOnDelete();
            $table->foreignId('shipment_inventory_valuation_id')->constrained('shipment_inventory_valuations')->restrictOnDelete();
            $table->foreignId('shipment_movement_id')->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('shipment_ledger_id')->constrained('stock_ledger')->restrictOnDelete();
            $table->foreignId('production_receipt_movement_id')->constrained('stock_movements')->restrictOnDelete();
            $table->decimal('quantity',18,4);$table->decimal('unit_cost',19,6);$table->decimal('base_cogs',19,4);
            $table->string('currency',3);$table->unsignedInteger('d08_valuation_version');$table->char('d08_source_hash',64);
            $table->char('source_hash',64);$table->timestamp('created_at',6)->useCurrent();
            $table->unique(['shipment_cogs_id','shipment_line_id'],'uq_shipment_cogs_line');
            $table->unique(['company_id','shipment_inventory_valuation_id'],'uq_shipment_cogs_d08');
            $table->unique('source_hash','uq_shipment_cogs_line_hash');
        });
    }
    public function down():void{Schema::dropIfExists('shipment_cogs_lines');Schema::dropIfExists('shipment_cogs');}
};
