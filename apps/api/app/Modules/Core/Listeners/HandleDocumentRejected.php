<?php

namespace Modules\Core\Listeners;

use Modules\Core\Approval\Events\DocumentRejected;
use Modules\Qc\Services\NcrService;

class HandleDocumentRejected
{
    public function handle(DocumentRejected $event): void
    {
        if ($event->request->doc_type !== 'NCR') return;
        $approverId = (int) $event->request->steps()->where('decision', 'REJECTED')->latest('id')->value('approver_id');
        app(NcrService::class)->markRejected($event->request->doc_id, $approverId);
    }
}
