<?php

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Modules\Core\Models\AuditLog;
use Modules\Core\Support\CurrentCompany;

/**
 * BR-016: Audit trail append-only.
 * Who (user), What (action + before→after), When (created_at), IP, device, dokumen.
 * Tidak ada method update/delete — tabel ini insert-only by design.
 */
class AuditService
{
    public function record(
        string $action,
        Model|string $document,
        ?int $documentId = null,
        ?array $before = null,
        ?array $after = null,
        ?int $lineId = null,
        ?Request $request = null,
    ): AuditLog {
        $log = new AuditLog([
            'company_id' => CurrentCompany::id() ?? ($document instanceof Model ? $document->company_id : null),
            'user_id' => auth()->id(),
            'action' => $action,
            'document_type' => $document instanceof Model ? $document->getTable() : $document,
            'document_id' => $document instanceof Model ? $document->getKey() : $documentId,
            'document_line_id' => $lineId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
        $log->created_at = now();
        $log->save();

        return $log;
    }

    /**
     * Observer hook: catat create/update model yang terdaftar.
     * Dipanggil dari CoreServiceProvider untuk model dengan audit wajib.
     */
    public function recordModelEvent(string $action, Model $model): void
    {
        $this->record(
            action: $action,
            document: $model,
            before: $action === 'updated' ? array_intersect_key($model->getOriginal(), $model->getChanges()) : null,
            after: $model->getChanges() ?: null,
            request: app()->bound('request') ? request() : null,
        );
    }
}
