<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->unique(
                ['company_id', 'sales_order_id', 'style_id'],
                'uq_production_orders_so_style',
            );
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', fn (Blueprint $table) => $table->dropUnique('uq_production_orders_so_style'));
    }
};
