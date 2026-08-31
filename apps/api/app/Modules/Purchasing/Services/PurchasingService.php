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
        if (! in_array($source, PurchaseRequest::SOURCES, true)) {
            throw new RuntimeException('Source PR tidak valid.');
        }
        $this->assertCreatorCompany($creator, $companyId);
        $this->assertLines($companyId, $lines, false);

        return DB::transaction(function () use ($companyId, $header, $lines, $source, $creator): PurchaseRequest {
            $pr = PurchaseRequest::create([
                'company_id' => $companyId,
                'doc_no' => $this->numbering->next($companyId, 'PR'),
                'source' => $source,
                'needed_by' => $header['needed_by'] ?? null,
                'notes' => $header['notes'] ?? null,
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
            $this->assertCreatorCompany($submitter, (int) $locked->company_id);
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
        $this->assertCreatorCompany($creator, $companyId);
        $this->assertCompanyReference('suppliers', (int) ($header['supplier_id'] ?? 0), $companyId, 'Supplier');
        if (! empty($header['currency_id'])) {
            $this->assertCompanyReference('currencies', (int) $header['currency_id'], $companyId, 'Currency');
            if ((float) ($header['exchange_rate'] ?? 0) <= 0) {
                throw new RuntimeException('Exchange rate wajib lebih besar dari nol untuk PO ber-currency.');
            }
        }
        if (! empty($header['expected_date']) && $header['expected_date'] < $header['order_date']) {
            throw new RuntimeException('Expected date tidak boleh sebelum order date.');
        }
        $this->assertLines($companyId, $lines, true);

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
            $this->assertCreatorCompany($submitter, (int) $locked->company_id);
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

    private function assertLines(int $companyId, array $lines, bool $requirePrice): void
    {
        foreach ($lines as $line) {
            if ((float) ($line['qty'] ?? 0) <= 0) {
                throw new RuntimeException('Qty line purchasing wajib lebih besar dari nol.');
            }
            $this->assertCompanyReference('materials', (int) ($line['material_id'] ?? 0), $companyId, 'Material');
            $this->assertCompanyReference('uoms', (int) ($line['uom_id'] ?? 0), $companyId, 'UOM');
            if ($requirePrice && (float) ($line['unit_price'] ?? -1) < 0) {
                throw new RuntimeException('Harga PO tidak boleh negatif.');
            }
            if ($requirePrice && ! empty($line['pr_line_id'])) {
                $valid = DB::table('pr_lines')
                    ->join('purchase_requests', 'purchase_requests.id', '=', 'pr_lines.purchase_request_id')
                    ->where('pr_lines.id', (int) $line['pr_line_id'])
                    ->where('purchase_requests.company_id', $companyId)
                    ->exists();
                if (! $valid) {
                    throw new RuntimeException('PR line tidak berasal dari company aktif.');
                }
            }
        }
    }

    private function assertCompanyReference(string $table, int $id, int $companyId, string $label): void
    {
        if ($id <= 0 || ! DB::table($table)->where('id', $id)->where('company_id', $companyId)->exists()) {
            throw new RuntimeException("{$label} tidak ditemukan pada company aktif.");
        }
    }

    private function assertCreatorCompany(User $user, int $companyId): void
    {
        if ((int) $user->company_id === $companyId) {
            return;
        }
        if (! $user->companies()->whereKey($companyId)->exists()) {
            throw new RuntimeException('User tidak memiliki akses ke company dokumen.');
        }
    }
}
