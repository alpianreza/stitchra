<?php

namespace Modules\Purchasing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\User;
use Modules\Core\Services\NumberingService;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\PurchaseRequest;
use RuntimeException;

class PurchasingService
{
    public function __construct(private NumberingService $numbering, private ApprovalEngine $approval) {}

    public function createPr(int $companyId, array $header, array $lines, string $source, User $creator): PurchaseRequest
    {
        if ($lines === []) {
            throw new RuntimeException('PR wajib punya minimal 1 line.');
        }
        $this->assertLines($lines, false);

        return DB::transaction(function () use ($companyId, $header, $lines, $source, $creator): PurchaseRequest {
            $pr = PurchaseRequest::create([
                'company_id' => $companyId,
                'doc_no' => $this->numbering->next($companyId, 'PR'),
                'request_date' => $header['request_date'],
                'required_date' => $header['required_date'] ?? null,
                'department' => $header['department'] ?? null,
                'source' => $source,
                'status' => 'DRAFT',
                'created_by' => $creator->id,
            ]);
            foreach ($lines as $line) {
                $pr->lines()->create($line);
            }
            return $pr->load('lines');
        });
    }

    public function submitPr(PurchaseRequest $pr, User $submitter): void
    {
        DB::transaction(function () use ($pr, $submitter): void {
            $locked = PurchaseRequest::withoutGlobalScopes()->whereKey($pr->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'DRAFT') {
                throw new RuntimeException('Hanya PR DRAFT yang bisa disubmit.');
            }
            $locked->update(['status' => 'SUBMITTED', 'updated_by' => $submitter->id]);
            $this->approval->submit($locked, 'PR', $submitter);
        });
    }

    public function createPo(int $companyId, array $header, array $lines, User $creator): PurchaseOrder
    {
        if ($lines === []) {
            throw new RuntimeException('PO wajib punya minimal 1 line.');
        }
        $this->assertLines($lines, true);

        return DB::transaction(function () use ($companyId, $header, $lines, $creator): PurchaseOrder {
            $total = 0.0;
            foreach ($lines as $i => $line) {
                $total += (float) $line['qty'] * (float) $line['unit_price'];
                $lines[$i]['line_no'] = $i + 1;
            }

            $po = PurchaseOrder::create([
                'company_id' => $companyId,
                'doc_no' => $this->numbering->next($companyId, 'PO'),
                'supplier_id' => $header['supplier_id'],
                'currency_id' => $header['currency_id'] ?? null,
                'exchange_rate' => $header['exchange_rate'] ?? null,
                'order_date' => $header['order_date'],
                'expected_date' => $header['expected_date'] ?? null,
                'payment_term' => $header['payment_term'] ?? null,
                'total_amount' => round($total, 4),
                'status' => 'DRAFT',
                'created_by' => $creator->id,
            ]);
            foreach ($lines as $line) {
                $po->lines()->create($line);
            }
            return $po->load('lines');
        });
    }

    public function submitPo(PurchaseOrder $po, User $submitter): void
    {
        DB::transaction(function () use ($po, $submitter): void {
            $locked = PurchaseOrder::withoutGlobalScopes()->whereKey($po->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'DRAFT') {
                throw new RuntimeException('Hanya PO DRAFT yang bisa disubmit.');
            }
            $locked->update(['status' => 'SUBMITTED', 'updated_by' => $submitter->id]);
            $this->approval->submit($locked, 'PO', $submitter);
        });
    }

    public function markApproved(string $docType, int $docId): void
    {
        if (! in_array($docType, ['PR', 'PO'], true)) {
            throw new RuntimeException("Tipe dokumen purchasing [{$docType}] tidak didukung.");
        }

        DB::transaction(function () use ($docType, $docId): void {
            $model = $docType === 'PR' ? PurchaseRequest::class : PurchaseOrder::class;
            $document = $model::withoutGlobalScopes()->whereKey($docId)->lockForUpdate()->firstOrFail();
            if ($document->status === 'APPROVED') {
                return;
            }
            if ($document->status !== 'SUBMITTED') {
                throw new RuntimeException("Dokumen {$docType} tidak berada pada status SUBMITTED.");
            }
            $document->update(['status' => 'APPROVED']);
        });
    }

    private function assertLines(array $lines, bool $requirePrice): void
    {
        foreach ($lines as $line) {
            if ((float) ($line['qty'] ?? 0) <= 0) {
                throw new RuntimeException('Qty line purchasing wajib lebih besar dari nol.');
            }
            if ((int) ($line['material_id'] ?? 0) <= 0 || (int) ($line['uom_id'] ?? 0) <= 0) {
                throw new RuntimeException('Material dan UOM line purchasing wajib diisi.');
            }
            if ($requirePrice && (float) ($line['unit_price'] ?? -1) < 0) {
                throw new RuntimeException('Harga PO tidak boleh negatif.');
            }
        }
    }
}
