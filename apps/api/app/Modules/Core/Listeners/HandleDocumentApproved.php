<?php

namespace Modules\Core\Listeners;

use Modules\Core\Approval\Events\DocumentApproved;
use Modules\ProductDev\Models\BomVersion;
use Modules\ProductDev\Models\CostSheet;
use Modules\ProductDev\Models\RoutingVersion;
use Modules\ProductDev\Services\BomService;
use Modules\ProductDev\Services\CostingService;
use Modules\ProductDev\Services\RoutingService;
use Modules\Purchasing\Services\PurchasingService;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;

/**
 * Satu pintu: approval APPROVED → tindakan domain per doc_type (BR-015).
 * Modul baru cukup mendaftarkan doc_type di sini.
 */
class HandleDocumentApproved
{
    public function handle(DocumentApproved $event): void
    {
        $request = $event->request;

        match ($request->doc_type) {
            'SO' => app(SalesOrderService::class)->markApproved(
                SalesOrder::withoutGlobalScopes()->findOrFail($request->doc_id)),
            'BOM' => app(BomService::class)->markApproved(
                BomVersion::findOrFail($request->doc_id)),
            'ROUTING' => app(RoutingService::class)->markApproved(
                RoutingVersion::findOrFail($request->doc_id)),
            'COST' => app(CostingService::class)->markApproved(
                CostSheet::withoutGlobalScopes()->findOrFail($request->doc_id)),
            'PR', 'PO' => app(PurchasingService::class)->markApproved($request->doc_type, $request->doc_id),
            default => null,
        };
    }
}
