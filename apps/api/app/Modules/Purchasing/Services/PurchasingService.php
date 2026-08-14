<?php

namespace Modules\Purchasing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\User;
use Modules\Core\Services\NumberingService;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\PurchaseRequest;
use RuntimeException;

/** PR & PO — approval berjenjang by nilai via approval matrix (BR-015). */
class PurchasingService
{
    public function __construct(
        private NumberingService $numbering,
        private ApprovalEngine $approval,
    ) {}

    public function createPr(int $companyId, array $header, array $lines, string $source, User $creator): PurchaseRequest
    {
        if (empty($lines)) {
            throw new RuntimeException('PR wajib punya minimal 1 line.');
        }

        return DB::transaction(function () use ($companyId, $header, $lines, $source, $creator): PurchaseRequest {
            $pr = PurchaseRequest::create(array_merge($header, [
                'company_id' => $companyId,
                'doc_no' => $this->numbering->next($companyId, 'PR'),
                'source' => $source,
                'status' => 'DRAFT',
                'created_by' => $creator->id,
            ]));

            foreach ($lines as $line) {
                $pr->lines()->create($line);
            }

            return $pr->load('lines');
        });
    }

    public function submitPr(PurchaseRequest $pr, User $submitter): void
    {
        if ($pr->status !== 'DRAFT') {
            throw new RuntimeException('Hanya PR DRAFT yang bisa disubmit.');
        }

        $pr->update(['status' => 'SUBMITTED']);
        $this->approval->submit($pr, 'PR', $submitter);
    }

    /** Buat PO dari PR APPROVED (convert) atau manual. */
    public function createPo(int $companyId, array $header, array $lines, User $creator): PurchaseOrder
    {
        if (empty($lines)) {
            throw new RuntimeException('PO wajib punya minimal 1 line.');
        }

        return DB::transaction(function () use ($companyId, $header, $lines, $creator): PurchaseOrder {
            $total = 0;
            foreach ($lines as $i => $line) {
                $total += (float) $line['qty'] * (float) $line['unit_price'];
                $lines[$i]['line_no'] = $i + 1;
            }

            $po = PurchaseOrder::create(array_merge($header, [
                'company_id' => $companyId,
                'doc_no' => $this->numbering->next($companyId, 'PO'),
                'total_amount' => round($total, 4),
                'status' => 'DRAFT',
                'created_by' => $creator->id,
            ]));

            foreach ($lines as $line) {
                $po->lines()->create($line);
            }

            return $po->load('lines');
        });
    }

    public function submitPo(PurchaseOrder $po, User $submitter): void
    {
        if ($po->status !== 'DRAFT') {
            throw new RuntimeException('Hanya PO DRAFT yang bisa disubmit.');
        }

        $po->update(['status' => 'SUBMITTED']);
        $this->approval->submit($po, 'PO', $submitter);
    }

    /** Listener hook: approval APPROVED doc_type PR/PO */
    public function markApproved(string $docType, int $docId): void
    {
        $model = $docType === 'PR' ? PurchaseRequest::class : PurchaseOrder::class;
        $model::withoutGlobalScopes()->where('id', $docId)->update(['status' => 'APPROVED']);
    }
}
