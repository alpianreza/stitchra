<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up():void
    {
        DB::statement('ALTER TABLE subcon_order_lines ADD CONSTRAINT chk_subcon_line_qty CHECK (qty_sent > 0 AND qty_returned >= 0 AND qty_returned <= qty_sent)');
        DB::statement('ALTER TABLE subcon_fees ADD CONSTRAINT chk_subcon_fee_qty CHECK (qty_returned > 0 AND fee_per_pcs >= 0 AND total_fee >= 0)');
        Schema::table('subcon_order_lines',fn(Blueprint $table)=>$table->index(['subcon_order_id','material_id','bundle_id'],'idx_subcon_line_identity'));
    }
    public function down():void
    {
        Schema::table('subcon_order_lines',fn(Blueprint $table)=>$table->dropIndex('idx_subcon_line_identity'));
        DB::statement('ALTER TABLE subcon_fees DROP CHECK chk_subcon_fee_qty');
        DB::statement('ALTER TABLE subcon_order_lines DROP CHECK chk_subcon_line_qty');
    }
};
