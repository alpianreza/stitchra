<?php

use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
 public function up():void
 {
  Schema::create('accounting_corrections',function(Blueprint$table):void{
   $table->id();$table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
   $table->foreignId('original_journal_id')->constrained('journals')->restrictOnDelete();
   $table->string('original_source_type',64);$table->unsignedBigInteger('original_source_id');$table->string('original_event',32);
   $table->string('original_gl_period',7);$table->date('original_posting_date');$table->decimal('original_amount',19,4);
   $table->decimal('corrected_amount',19,4);$table->decimal('adjustment_amount',19,4);$table->string('currency',3);
   $table->string('period_state',8);$table->string('correction_mode',16);$table->text('reason');$table->string('status',32);
   $table->foreignId('account_mapping_id')->constrained('account_mappings')->restrictOnDelete();
   $table->foreignId('debit_account_id')->constrained('chart_of_accounts')->restrictOnDelete();$table->foreignId('credit_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
   $table->string('adjustment_period',7)->nullable();$table->date('adjustment_posting_date')->nullable();
   $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests')->restrictOnDelete();
   $table->foreignId('reversal_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
   $table->foreignId('corrected_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
   $table->foreignId('adjustment_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
   $table->unsignedInteger('correction_version');$table->char('source_hash',64);
   $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();$table->timestamp('requested_at',6);
   $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();$table->timestamp('approved_at',6)->nullable();
   $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();$table->timestamp('posted_at',6)->nullable();$table->timestamp('created_at',6)->useCurrent();
   $table->unique(['company_id','original_journal_id','correction_version'],'uq_accounting_correction_version');
   $table->unique('source_hash','uq_accounting_correction_hash');$table->unique('approval_request_id','uq_accounting_correction_approval');
   $table->unique('reversal_journal_id','uq_accounting_correction_reversal');$table->unique('corrected_journal_id','uq_accounting_correction_repost');$table->unique('adjustment_journal_id','uq_accounting_correction_adjustment');
   $table->index(['company_id','status','period_state'],'idx_accounting_correction_status');
  });
 }
 public function down():void{Schema::dropIfExists('accounting_corrections');}
};
