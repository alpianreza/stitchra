<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_sheets', function (Blueprint $table): void {
            $table->unique(
                ['company_id', 'style_id', 'version'],
                'uq_cost_sheets_company_style_version',
            );
        });
    }

    public function down(): void
    {
        Schema::table('cost_sheets', function (Blueprint $table): void {
            $table->dropUnique('uq_cost_sheets_company_style_version');
        });
    }
};
