<?php

namespace Modules\Core\Listeners;

use Modules\Core\Approval\Events\DocumentRejected;
use Modules\Qc\Services\NcrService;

class HandleDocumentRejected
{
    public function handle(DocumentRejected $event): void
    {
        $request = $event->request;
        if ($request->doc_type !== 'NCR') {
            return;
        }

        $approverId = (int) $request->stepInstances()
            ->where('decision', 'REJECTED')
            ->latest('id')
            ->value('approver_id');

        app(NcrService::class)->markRejected($request->doc_id, $approverId);
    }
}
