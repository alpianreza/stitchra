<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up():void
    {
        if(!Schema::hasIndex('ar_invoices','uq_ar_invoice_shipment'))Schema::table('ar_invoices',fn(Blueprint $table)=>$table->unique('shipment_id','uq_ar_invoice_shipment'));
        DB::statement('ALTER TABLE ar_invoices ADD CONSTRAINT chk_ar_invoice_amounts CHECK (total_amount > 0 AND paid_amount >= 0 AND paid_amount <= total_amount AND exchange_rate > 0)');
        DB::statement('ALTER TABLE ar_payments ADD CONSTRAINT chk_ar_payment_amount CHECK (amount > 0)');
        DB::statement('ALTER TABLE ap_payments ADD CONSTRAINT chk_ap_payment_amount CHECK (amount > 0)');
    }
    public function down():void
    {
        DB::statement('ALTER TABLE ap_payments DROP CHECK chk_ap_payment_amount');DB::statement('ALTER TABLE ar_payments DROP CHECK chk_ar_payment_amount');DB::statement('ALTER TABLE ar_invoices DROP CHECK chk_ar_invoice_amounts');
        if(Schema::hasIndex('ar_invoices','uq_ar_invoice_shipment'))Schema::table('ar_invoices',fn(Blueprint $table)=>$table->dropUnique('uq_ar_invoice_shipment'));
    }
};
