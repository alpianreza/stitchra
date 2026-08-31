<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rework_records', function (Blueprint $table): void {
            $table->timestamp('resolved_at', 6)->nullable()->after('notes');
            $table->unsignedBigInteger('resolved_by')->nullable()->after('resolved_at');
            $table->foreign('resolved_by', 'fk_rework_resolved_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['bundle_id', 'resolved_at'], 'idx_rework_bundle_open');
        });
        DB::statement('ALTER TABLE rework_records ADD CONSTRAINT chk_rework_qty_positive CHECK (qty > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE rework_records DROP CONSTRAINT chk_rework_qty_positive');
        Schema::table('rework_records', function (Blueprint $table): void {
            $table->dropIndex('idx_rework_bundle_open');
            $table->dropForeign('fk_rework_resolved_by');
            $table->dropColumn(['resolved_at', 'resolved_by']);
        });
    }
};
