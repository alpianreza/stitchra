<?php

namespace Modules\Receiving\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Inventory\Models\StockLedger;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\Receiving\Models\FabricRoll;
use Modules\Receiving\Models\GoodsReceipt;
use Modules\Receiving\Models\GrLine;
use Modules\Receiving\Models\InwardInspection;
use Modules\Receiving\Models\SupplierReturn;
use RuntimeException;

class InwardQcService
{
    public function __construct(
        private NumberingService $numbering,
        private InventoryTransactionService $its,
        private ReceiptStockService $stock,
        private AuditService $audit,
    ) {}

    public function create(int $companyId, GoodsReceipt $gr, array $lines, User $user): InwardInspection
    {
        $this->stock->authorize($companyId, $user);
        if ((int) $gr->company_id !== $companyId) {
            throw new RuntimeException('GR berasal dari company lain.');
        }
        $data = Validator::make(['lines' => $lines], [
            'lines' => 'required|array|min:1|max:200', 'lines.*.gr_line_id' => 'required|integer|min:1',
            'lines.*.roll_id' => 'nullable|integer|min:1', 'lines.*.result' => ['required', Rule::in(['PASS', 'FAIL'])],
            'lines.*.four_point_points' => 'nullable|numeric|min:0', 'lines.*.shrinkage_pct_actual' => 'nullable|numeric',
            'lines.*.gsm_actual' => 'nullable|numeric|gt:0', 'lines.*.shade_verdict' => ['nullable', Rule::in(['MATCH', 'DEVIATION'])],
            'lines.*.defect_id' => ['nullable', 'integer', Rule::exists('defect_library', 'id')->where('company_id', $companyId)],
            'lines.*.notes' => 'nullable|string|max:5000',
        ])->validate();

        return DB::transaction(function () use ($companyId, $gr, $data, $user): InwardInspection {
            $gr = $this->stock->lockGr($companyId, (int) $gr->id, $user);
            $inspection = InwardInspection::create([
                'company_id' => $companyId, 'doc_no' => $this->numbering->next($companyId, 'FQC'),
                'goods_receipt_id' => $gr->id, 'result' => 'PENDING', 'created_by' => $user->id,
            ]);
            $seen = [];
            foreach ($data['lines'] as $input) {
                [$line, $roll, $receipt] = $this->stock->resolve($gr, (int) $input['gr_line_id'], ! empty($input['roll_id']) ? (int) $input['roll_id'] : null);
                if (isset($seen[$receipt->id])) {
                    throw new RuntimeException('Inspection unit duplikat.');
                }
                $seen[$receipt->id] = true;
                $this->assertUninspected($line, $roll, $receipt);
                $inspection->lines()->create(array_intersect_key($input, array_flip([
                    'gr_line_id', 'roll_id', 'result', 'four_point_points', 'shrinkage_pct_actual',
                    'gsm_actual', 'shade_verdict', 'defect_id', 'notes',
                ])));
            }
            $results = $inspection->lines()->pluck('result')->unique();
            $inspection->update(['result' => $results->count() === 1 ? $results->first() : 'PARTIAL']);
            $this->audit->record('create', $inspection, after: $inspection->load('lines')->toArray());

            return $inspection;
        });
    }

    /** Client stock dimensions are ignored; persisted measurements and receipt ledger are authoritative. */
    public function finalize(InwardInspection $inspection, array $ignored, User $user): void
    {
        $this->stock->authorize((int) $inspection->company_id, $user);
        DB::transaction(function () use ($inspection, $user): void {
            // All receiving operations use GR -> document -> unit locking order.
            $gr = $this->stock->lockGr((int) $inspection->company_id, (int) $inspection->goods_receipt_id, $user);
            $locked = InwardInspection::withoutGlobalScopes()->where('company_id', $gr->company_id)
                ->where('goods_receipt_id', $gr->id)->whereKey($inspection->id)->lockForUpdate()->firstOrFail();
            if ($locked->finalized_at) {
                return;
            }
            $inspectionLines = $locked->lines()->orderBy('id')->lockForUpdate()->get();
            if ($inspectionLines->isEmpty()) {
                throw new RuntimeException('Inspeksi tanpa line tidak dapat difinalisasi.');
            }
            $affected = [];
            foreach ($inspectionLines as $inspectionLine) {
                [$line, $roll, $receipt] = $this->stock->resolve($gr, (int) $inspectionLine->gr_line_id, $inspectionLine->roll_id ? (int) $inspectionLine->roll_id : null);
                $this->assertUninspected($line, $roll, $receipt);
                if ($inspectionLine->result === 'PASS') {
                    $payload = $this->stock->stockLine($receipt, (float) $receipt->qty_in, (int) $inspectionLine->id);
                    $payload['source_document_type'] = 'inward_inspections';
                    $payload['source_document_id'] = $locked->id;
                    $this->its->releaseQualityHold((int) $gr->company_id, $payload, (float) $receipt->qty_in, $user);
                } elseif ($inspectionLine->result !== 'FAIL') {
                    throw new RuntimeException('Hasil inspeksi tidak valid.');
                }
                $status = $inspectionLine->result === 'PASS' ? 'RELEASED' : 'REJECTED_RETURNED';
                if ($roll) {
                    $roll->update(['status' => $status]);
                } else {
                    $line->update(['status' => $status]);
                }
                $affected[$line->id] = $line;
            }
            foreach ($affected as $line) {
                $statuses = $line->rolls()->withoutGlobalScopes()->pluck('status')->unique();
                if ($statuses->isNotEmpty()) {
                    $line->update(['status' => $statuses->count() === 1 ? $statuses->first() : 'PARTIAL']);
                }
            }
            $locked->update(['finalized_at' => now(), 'updated_by' => $user->id]);
            $this->audit->record('finalize', $locked, after: ['result' => $locked->result, 'finalized_at' => $locked->finalized_at]);
        }, 3);
    }

    /** Compatibility: internal callers now create normalized evidence and post the same controlled return. */
    public function returnGoods(int $companyId, GoodsReceipt $gr, array $lines, string $reason, User $user): SupplierReturn
    {
        return DB::transaction(function () use ($companyId, $gr, $lines, $reason, $user): SupplierReturn {
            $service = app(SupplierReturnService::class);

            return $service->post($service->create($companyId, $gr, $lines, $reason, $user), $user);
        }, 3);
    }

    private function assertUninspected(GrLine $line, ?FabricRoll $roll, StockLedger $receipt): void
    {
        if (($roll?->status ?? $line->status) !== 'QUALITY_HOLD' || $this->stock->qcResult($receipt) !== null) {
            throw new RuntimeException('Unit sudah diputuskan QC; reinspection tidak boleh me-release/menolak stok ulang.');
        }
    }
}
