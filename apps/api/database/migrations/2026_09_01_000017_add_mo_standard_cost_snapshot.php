<?php

use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration{
 public function up():void{Schema::table('production_orders',function(Blueprint$t){$t->foreignId('standard_cost_sheet_id')->nullable()->after('routing_version_id')->constrained('cost_sheets')->restrictOnDelete();$t->json('standard_cost_snapshot')->nullable()->after('standard_cost_sheet_id');$t->char('standard_cost_snapshot_hash',64)->nullable()->after('standard_cost_snapshot');$t->timestamp('standard_cost_snapshotted_at',6)->nullable()->after('standard_cost_snapshot_hash');$t->string('standard_cost_snapshot_source',16)->nullable()->after('standard_cost_snapshotted_at');$t->index(['company_id','standard_cost_sheet_id'],'idx_mo_standard_cost');});}
 public function down():void{Schema::table('production_orders',function(Blueprint$t){$t->dropIndex('idx_mo_standard_cost');$t->dropForeign(['standard_cost_sheet_id']);$t->dropColumn(['standard_cost_sheet_id','standard_cost_snapshot','standard_cost_snapshot_hash','standard_cost_snapshotted_at','standard_cost_snapshot_source']);});}
};
