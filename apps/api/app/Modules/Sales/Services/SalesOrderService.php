<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\User;
use Modules\Core\Services\NumberingService;
use Modules\ProductDev\Services\BomService;
use Modules\ProductDev\Services\RoutingService;
use Modules\Sales\Models\SalesOrder;
use RuntimeException;

/**
 * Sales Order — BR-010 (nomor via numbering), BR-015 (approval),
 * BR-020 (matrix lines), BR-023 (confirm gate: butuh BOM+Routing APPROVED).
 */
class SalesOrderService
{
    public function __construct(
        private NumberingService $numbering,
        private ApprovalEngine $approval,
        private BomService $boms,
        private RoutingService $routings,
    ) {}

    public function create(int $companyId, array $header, array $lines, User $creator): SalesOrder
    {
        if (empty($lines)) {
            throw new RuntimeException('SO wajib punya minimal 1 line (style×color×size).');
        }

        return DB::transaction(function () use ($companyId, $header, $lines, $creator): SalesOrder {
            $so = SalesOrder::create(array_merge($header, [
                'company_id' => $companyId,
                'doc_no' => $this->numbering->next($companyId, 'SO'),
                'status' => 'DRAFT',
                'created_by' => $creator->id,
            ]));

            foreach ($lines as $line) {
                $so->lines()->create($line);
            }

            return $so->load('lines');
        });
    }

    public function submit(SalesOrder $so, User $submitter): void
    {
        if ($so->status !== 'DRAFT') {
            throw new RuntimeException('Hanya SO DRAFT yang bisa disubmit.');
        }

        $so->update(['status' => 'SUBMITTED']);
        $this->approval->submit($so, 'SO', $submitter);
    }

    /** Dipanggil listener setelah approval APPROVED. */
    public function markApproved(SalesOrder $so): void
    {
        $so->update(['status' => 'APPROVED']);
    }

    /**
     * BR-023: SO hanya bisa CONFIRMED bila semua style punya BOM & Routing APPROVED.
     * CONFIRMED = masuk radar MRP (Phase 5).
     */
    public function confirm(SalesOrder $so): SalesOrder
    {
        if ($so->status !== 'APPROVED') {
            throw new RuntimeException('Hanya SO APPROVED yang bisa di-confirm.');
        }

        $missing = [];
        $styleIds = $so->lines()->distinct()->pluck('style_id');

        foreach ($styleIds as $styleId) {
            if ($this->boms->activeVersion($styleId) === null) {
                $missing[] = "Style #{$styleId}: BOM belum APPROVED";
            }
            if ($this->routings->activeVersion($styleId) === null) {
                $missing[] = "Style #{$styleId}: Routing belum APPROVED";
            }
        }

        if (! empty($missing)) {
            throw new RuntimeException('BR-023: SO tidak bisa di-confirm — '.implode('; ', $missing));
        }

        $so->update(['status' => 'CONFIRMED']);

        return $so->fresh();
    }

    /**
     * BR-022: amendment hanya sebelum cutting dimulai.
     * Phase 3: modul produksi belum ada → belum ada cutting.
     * Hook ini membaca production_orders BILA tabel sudah ada (Phase 5+).
     */
    public function cuttingStarted(SalesOrder $so): bool
    {
        if (! Schema::hasTable('production_orders')) {
            return false;
        }

        return DB::table('production_orders')
            ->where('sales_order_id', $so->id)
            ->whereIn('status', ['CUTTING', 'SEWING', 'FINISHING', 'QC', 'PACKED', 'CLOSED'])
            ->exists();
    }
}
