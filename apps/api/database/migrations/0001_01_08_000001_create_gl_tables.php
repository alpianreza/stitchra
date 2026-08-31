<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up():void
    {
        Schema::create('gl_periods',function(Blueprint $table){$table->id();$table->foreignId('company_id')->constrained('companies')->restrictOnDelete();$table->string('period',7);$table->string('status',8)->default('OPEN');$table->timestamps(6);$table->unsignedBigInteger('closed_by')->nullable();$table->timestamp('closed_at',6)->nullable();$table->unique(['company_id','period'],'uq_gl_periods');});
        DB::statement("ALTER TABLE gl_periods ADD CONSTRAINT chk_gl_periods_status CHECK (status IN ('OPEN','CLOSED'))");
        Schema::create('journals',function(Blueprint $table){$table->id();$table->foreignId('company_id')->constrained('companies')->restrictOnDelete();$table->string('doc_no',32);$table->string('period',7);$table->date('journal_date');$table->string('source',8)->default('MANUAL');$table->string('event',32)->nullable();$table->string('source_document_type',64)->nullable();$table->unsignedBigInteger('source_document_id')->nullable();$table->string('posting_key',64)->nullable();$table->string('description')->nullable();$table->decimal('total_debit',19,4)->default(0);$table->decimal('total_credit',19,4)->default(0);$table->string('status',8)->default('POSTED');$table->unsignedBigInteger('reverses_journal_id')->nullable();$table->timestamps(6);$table->unsignedBigInteger('created_by')->nullable();$table->unique(['company_id','doc_no'],'uq_journals_doc_no');$table->unique('posting_key','uq_journals_posting_key');$table->unique('reverses_journal_id','uq_journals_reversal');$table->index(['company_id','period'],'idx_journals_period');$table->index(['source_document_type','source_document_id'],'idx_journals_source');$table->foreign(['company_id','period'],'fk_journals_period')->references(['company_id','period'])->on('gl_periods')->restrictOnDelete();$table->foreign('reverses_journal_id','fk_journals_reverses')->references('id')->on('journals')->restrictOnDelete();});
        DB::statement("ALTER TABLE journals ADD CONSTRAINT chk_journals_source CHECK (source IN ('AUTO','MANUAL'))");DB::statement("ALTER TABLE journals ADD CONSTRAINT chk_journals_status CHECK (status IN ('POSTED','VOID'))");
        Schema::create('journal_lines',function(Blueprint $table){$table->id();$table->foreignId('journal_id')->constrained('journals')->restrictOnDelete();$table->foreignId('coa_id')->constrained('chart_of_accounts')->restrictOnDelete();$table->decimal('debit',19,4)->default(0);$table->decimal('credit',19,4)->default(0);$table->string('memo')->nullable();$table->timestamps(6);$table->index('journal_id','idx_journal_lines_journal');$table->index('coa_id','idx_journal_lines_coa');});
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT chk_journal_lines_dc CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))');
        Schema::create('account_mappings',function(Blueprint $table){$table->id();$table->foreignId('company_id')->constrained('companies')->restrictOnDelete();$table->string('event',32);$table->foreignId('debit_account_id')->constrained('chart_of_accounts')->restrictOnDelete();$table->foreignId('credit_account_id')->constrained('chart_of_accounts')->restrictOnDelete();$table->timestamps(6);$table->unsignedBigInteger('created_by')->nullable();$table->unsignedBigInteger('updated_by')->nullable();$table->unique(['company_id','event'],'uq_account_mappings_event');});
    }
    public function down():void{Schema::dropIfExists('account_mappings');Schema::dropIfExists('journal_lines');Schema::dropIfExists('journals');Schema::dropIfExists('gl_periods');}
};
