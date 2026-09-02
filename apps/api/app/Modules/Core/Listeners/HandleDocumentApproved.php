<?php

namespace Modules\Core\Listeners;

use Modules\Core\Approval\Events\DocumentApproved;
use Modules\Inventory\Services\InventoryOpsService;
use Modules\ProductDev\Models\BomVersion;
use Modules\ProductDev\Models\CostSheet;
use Modules\ProductDev\Models\RoutingVersion;
use Modules\ProductDev\Services\BomService;
use Modules\ProductDev\Services\CostingService;
use Modules\ProductDev\Services\RoutingService;
use Modules\Purchasing\Services\PurchasingService;
use Modules\Qc\Services\NcrService;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;

class HandleDocumentApproved
{
    public function handle(DocumentApproved $event): void
    {
        $request = $event->request;

        match ($request->doc_type) {
            'SO' => app(SalesOrderService::class)->markApproved(SalesOrder::withoutGlobalScopes()->findOrFail($request->doc_id)),
            'BOM' => app(BomService::class)->markApproved(BomVersion::findOrFail($request->doc_id)),
            'ROUTING' => app(RoutingService::class)->markApproved(RoutingVersion::findOrFail($request->doc_id)),
            'COST' => app(CostingService::class)->markApproved(CostSheet::withoutGlobalScopes()->findOrFail($request->doc_id)),
            'PR', 'PO' => app(PurchasingService::class)->markApproved($request->doc_type, $request->doc_id),
            'ADJ' => app(InventoryOpsService::class)->applyAdjustmentOnApproval($request->doc_id),
            'OPN' => app(InventoryOpsService::class)->applyOpnameOnApproval($request->doc_id),
            'NCR' => app(NcrService::class)->markApproved($request->doc_id, (int) $event->request->steps()->where('decision', 'APPROVED')->latest('id')->value('approver_id')),
            default => null,
        };
    }
}
