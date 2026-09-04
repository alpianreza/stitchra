<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_schedules', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->restrictOnDelete();
            $table->string('status', 16)->default('OPEN')->after('destination');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['company_id', 'delivery_date', 'status'], 'idx_delivery_schedule_plan');
        });
        DB::statement('UPDATE delivery_schedules ds JOIN sales_orders so ON so.id = ds.sales_order_id SET ds.company_id = so.company_id WHERE ds.company_id IS NULL');
        DB::statement("ALTER TABLE delivery_schedules ADD CONSTRAINT chk_delivery_schedule_status CHECK (status IN ('OPEN','FULFILLED','CANCELLED'))");
        DB::statement('ALTER TABLE delivery_schedules ADD CONSTRAINT chk_delivery_schedule_qty CHECK (qty > 0)');

        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('delivery_schedule_id')->nullable()->after('packing_list_id')->constrained('delivery_schedules')->restrictOnDelete();
            $table->index('delivery_schedule_id', 'idx_shipments_delivery_schedule');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign(['delivery_schedule_id']);
            $table->dropIndex('idx_shipments_delivery_schedule');
            $table->dropColumn('delivery_schedule_id');
        });
        Schema::table('delivery_schedules', function (Blueprint $table) {
            $table->dropIndex('idx_delivery_schedule_plan');
            $table->dropForeign(['company_id']);
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropColumn(['company_id', 'status', 'created_by', 'updated_by']);
        });
    }
};
