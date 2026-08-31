<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inward_inspections', 'finalized_at')) {
            Schema::table('inward_inspections', function (Blueprint $table): void {
                $table->timestamp('finalized_at', 6)->nullable()->after('result');
            });
        }

        DB::statement('ALTER TABLE gr_lines DROP CHECK chk_gr_lines_status');
        DB::statement("ALTER TABLE gr_lines ADD CONSTRAINT chk_gr_lines_status CHECK (status IN ('QUALITY_HOLD','PARTIAL','RELEASED','REJECTED_RETURNED'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE gr_lines DROP CHECK chk_gr_lines_status');
        DB::statement("ALTER TABLE gr_lines ADD CONSTRAINT chk_gr_lines_status CHECK (status IN ('QUALITY_HOLD','RELEASED','REJECTED_RETURNED'))");
        if (Schema::hasColumn('inward_inspections', 'finalized_at')) {
            Schema::table('inward_inspections', fn (Blueprint $table) => $table->dropColumn('finalized_at'));
        }
    }
};
