<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up():void
    {
        if(!Schema::hasColumn('journals','posting_key'))Schema::table('journals',fn(Blueprint $table)=>$table->string('posting_key',64)->nullable()->after('source_document_id'));
        if(!Schema::hasIndex('journals','uq_journals_posting_key'))Schema::table('journals',fn(Blueprint $table)=>$table->unique('posting_key','uq_journals_posting_key'));
        if(!Schema::hasIndex('journals','uq_journals_reversal'))Schema::table('journals',fn(Blueprint $table)=>$table->unique('reverses_journal_id','uq_journals_reversal'));
    }
    public function down():void
    {
        if(Schema::hasIndex('journals','uq_journals_posting_key'))Schema::table('journals',fn(Blueprint $table)=>$table->dropUnique('uq_journals_posting_key'));
        if(Schema::hasIndex('journals','uq_journals_reversal'))Schema::table('journals',fn(Blueprint $table)=>$table->dropUnique('uq_journals_reversal'));
        if(Schema::hasColumn('journals','posting_key'))Schema::table('journals',fn(Blueprint $table)=>$table->dropColumn('posting_key'));
    }
};
