<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packing_source_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('packing_list_id')->constrained('packing_lists')->restrictOnDelete();
            $table->foreignId('qc_inspection_id')->constrained('qc_inspections')->restrictOnDelete();
            $table->text('reason');
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('applied_at', 6)->nullable();
            $table->timestamps(6);

            $table->index(['company_id', 'packing_list_id'], 'idx_packing_source_attachments_pl');
            $table->unique('approval_request_id', 'uq_packing_source_attachment_approval');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packing_source_attachments');
    }
};
