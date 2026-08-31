<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up():void
    {
        Schema::table('journals',function(Blueprint $table){if(!Schema::hasColumn('journals','posting_key'))$table->string('posting_key',64)->nullable()->after('source_document_id');});
        Schema::table('journals',function(Blueprint $table){$table->unique('posting_key','uq_journals_posting_key');$table->unique('reverses_journal_id','uq_journals_reversal');});
    }
    public function down():void{Schema::table('journals',function(Blueprint $table){$table->dropUnique('uq_journals_posting_key');$table->dropUnique('uq_journals_reversal');if(Schema::hasColumn('journals','posting_key'))$table->dropColumn('posting_key');});}
};
