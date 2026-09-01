<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up():void
    {
        Schema::table('shipments',fn(Blueprint $table)=>$table->unique('packing_list_id','uq_shipments_packing_list'));
        Schema::table('shipment_lines',fn(Blueprint $table)=>$table->unique(['shipment_id','style_id','colorway_id','size_id'],'uq_shipment_lines_matrix'));
    }
    public function down():void
    {
        Schema::table('shipment_lines',fn(Blueprint $table)=>$table->dropUnique('uq_shipment_lines_matrix'));
        Schema::table('shipments',fn(Blueprint $table)=>$table->dropUnique('uq_shipments_packing_list'));
    }
};
