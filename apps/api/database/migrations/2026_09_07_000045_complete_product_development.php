<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('style_specs', function (Blueprint $table) {
            $table->text('revision_notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
        });
        Schema::table('measurement_charts', function (Blueprint $table) {
            $table->string('unit', 8)->nullable(); // Legacy measurements have no assumed unit.
            $table->text('revision_notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
        });
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->string('disk', 32)->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->text('revision_notes')->nullable();
            $table->uuid('upload_key')->nullable();
            $table->unique(['company_id', 'upload_key'], 'uq_tech_pack_upload');
        });
        Schema::table('samples', function (Blueprint $table) {
            $table->foreignId('style_spec_id')->nullable()->constrained('style_specs')->restrictOnDelete();
            $table->foreignId('measurement_chart_id')->nullable()->constrained('measurement_charts')->restrictOnDelete();
            $table->foreignId('tech_pack_id')->nullable()->constrained('tech_packs')->restrictOnDelete();
            $table->foreignId('revision_of_id')->nullable()->constrained('samples')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->uuid('request_key')->nullable();
            $table->unique(['company_id', 'request_key'], 'uq_sample_request');
        });
        Schema::table('sample_approvals', function (Blueprint $table) {
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('response_reference')->nullable();
            $table->uuid('request_key')->nullable();
            $table->unique(['sample_id', 'request_key'], 'uq_sample_response');
        });
        Schema::table('production_orders', function (Blueprint $table) {
            $table->foreignId('production_sample_id')->nullable()->constrained('samples')->restrictOnDelete();
            $table->foreignId('sample_gate_approval_id')->nullable()->constrained('sample_approvals')->restrictOnDelete();
            $table->json('sample_gate_snapshot')->nullable();
            $table->timestamp('sample_gate_checked_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('production_sample_id');
            $table->dropConstrainedForeignId('sample_gate_approval_id');
            $table->dropColumn(['sample_gate_snapshot', 'sample_gate_checked_at']);
        });
        Schema::table('sample_approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recorded_by');
            $table->dropUnique('uq_sample_response');
            $table->dropColumn(['response_reference', 'request_key']);
        });
        Schema::table('samples', function (Blueprint $table) {
            foreach (['style_spec_id', 'measurement_chart_id', 'tech_pack_id', 'revision_of_id'] as $column) {
                $table->dropConstrainedForeignId($column);
            }
            $table->dropUnique('uq_sample_request');
            $table->dropColumn(['notes', 'request_key']);
        });
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->dropUnique('uq_tech_pack_upload');
            $table->dropColumn(['disk', 'mime_type', 'size_bytes', 'sha256', 'revision_notes', 'upload_key']);
        });
        Schema::table('measurement_charts', fn (Blueprint $table) => $table->dropColumn(['unit', 'revision_notes', 'created_by']));
        Schema::table('style_specs', fn (Blueprint $table) => $table->dropColumn(['revision_notes', 'created_by']));
    }
};