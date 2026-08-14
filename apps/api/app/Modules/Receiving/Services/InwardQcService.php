<?php

namespace Modules\Receiving\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\NumberingService;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\Receiving\Models\FabricRoll;
use Modules\Receiving\Models\GoodsReceipt;
use Modules\Receiving\Models\InwardInspection;
use RuntimeException;

/**
 * Inward QC — BR-004: PASS → release quality hold (ITS QUALITY_RELEASE);
 * FAIL → supplier return + PURCHASE_RETURN via ITS. Partial per roll dimungkinkan.
 */
class InwardQcService
{
    public function __construct(
        private NumberingService $numbering,
        private InventoryTransactionService $its,
    ) {}

    public function create(int $companyId, GoodsReceipt $gr, array $lines, User $user): InwardInspection
    {
        if (empty($lines)) {
            throw new RuntimeException('Inspeksi wajib punya minimal 1 line.');
        }

        return DB::transaction(function () use ($companyId, $gr, $lines, $user): InwardInspection {
            $inspection = InwardInspection::create([
                'company_id' => $companyId,
                'doc_no' => $this->numbering->next($companyId, 'FQC'),
                'goods_receipt_id' => $gr->id,
                'inspector_id' => null,
                'result' => 'PENDING',
                'created_by' => $user->id,
            ]);

            $results = [];
            foreach ($lines as $line) {
                $inspection->lines()->create($line);
                $results[] = $line['result'];
            }

            $inspection->result = match (true) {
                in_array('FAIL', $results, true) && in_array('PASS', $results, true) => 'PARTIAL',
                in_array('FAIL', $results, true) => 'FAIL',
                default => 'PASS',
            };
            $inspection->save();

            return $inspection->load('lines');
        });
    }

    /**
     * Finalisasi inspeksi: per line PASS → release hold; FAIL → return.
     * $lines[]: gr_line_id, roll_id?, result PASS|FAIL, qty (dalam UOM stok), uom_id,
     *           warehouse_id, material_id, unit_cost
     */
    public function finalize(InwardInspection $inspection, array $lines, User $user): void
    {
        DB::transaction(function () use ($inspection, $lines, $user): void {
            foreach ($lines as $line) {
                if ($line['result'] === 'PASS') {
                    // BR-004: hold → available
                    $this->its->releaseQualityHold($inspection->company_id, [
                        'material_id' => $line['material_id'],
                        'warehouse_id' => $line['warehouse_id'],
                        'lot_no' => $line['lot_no'] ?? null,
                        'roll_id' => $line['roll_id'] ?? null,
                        'uom_id' => $line['uom_id'],
                        'source_document_type' => 'inward_inspections',
                        'source_document_id' => $inspection->id,
                        'source_document_line_id' => $line['gr_line_id'],
                    ], (float) $line['qty'], $user);

                    DB::table('gr_lines')->where('id', $line['gr_line_id'])->update(['status' => 'RELEASED']);

                    if (! empty($line['roll_id'])) {
                        FabricRoll::where('id', $line['roll_id'])->update(['status' => 'RELEASED']);
                    }
                } else {
                    // FAIL → mark rejected; supplier return dibuat terpisah (returnGoods)
                    DB::table('gr_lines')->where('id', $line['gr_line_id'])->update(['status' => 'REJECTED_RETURNED']);

                    if (! empty($line['roll_id'])) {
                        FabricRoll::where('id', $line['roll_id'])->update(['status' => 'REJECTED_RETURNED']);
                    }
                }
            }
        });
    }

    /** BR-004: return barang FAIL ke supplier ⇒ ITS PURCHASE_RETURN (dari quality_hold). */
    public function returnGoods(int $companyId, GoodsReceipt $gr, array $returnLines, string $reason, User $user): void
    {
        DB::transaction(function () use ($companyId, $gr, $returnLines, $reason, $user): void {
            $return = $gr->purchaseOrder->supplier->returns()->create([
                'company_id' => $companyId,
                'doc_no' => $this->numbering->next($companyId, 'GR'),   // prefix return pakai GR group; numbering config terpisah bisa ditambah
                'goods_receipt_id' => $gr->id,
                'reason' => $reason,
                'status' => 'SUBMITTED',
                'created_by' => $user->id,
            ]);

            $itsLines = array_map(fn ($l) => [
                'material_id' => $l['material_id'],
                'warehouse_id' => $gr->warehouse_id,
                'lot_no' => $l['lot_no'] ?? null,
                'roll_id' => $l['roll_id'] ?? null,
                'qty' => (float) $l['qty'],
                'uom_id' => $l['uom_id'],
                'unit_cost' => $l['unit_cost'] ?? null,
                'source_document_line_id' => $l['gr_line_id'] ?? null,
            ], $returnLines);

            $this->its->post('PURCHASE_RETURN', [
                'company_id' => $companyId,
                'source_document_type' => 'supplier_returns',
                'source_document_id' => $return->id,
            ], $itsLines, $user);
        });
    }
}
