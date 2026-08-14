<?php

namespace Modules\Packing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\Packing\Models\Carton;
use Modules\Packing\Models\PackingList;
use Modules\Qc\Models\QcInspection;
use Modules\Sales\Models\SalesOrder;
use RuntimeException;

/**
 * PF-09: packing list per karton; ratio check vs SO matrix (BR-021/082).
 * BR-082: finalize memposting FG ke gudang FG via ITS PRODUCTION_RECEIPT —
 * hanya bila QC FINAL PASS.
 */
class PackingService
{
    public function __construct(
        private NumberingService $numbering,
        private InventoryTransactionService $its,
        private AuditService $audit,
    ) {}

    public function create(SalesOrder $so, ?int $moId, User $user): PackingList
    {
        if (! in_array($so->status, ['CONFIRMED', 'IN_PROGRESS'], true)) {
            throw new RuntimeException("Packing list hanya untuk SO CONFIRMED/IN_PROGRESS (status: {$so->status}).");
        }

        return PackingList::create([
            'company_id' => $so->company_id,
            'doc_no' => $this->numbering->next($so->company_id, 'PL'),
            'sales_order_id' => $so->id,
            'production_order_id' => $moId,
            'status' => 'DRAFT',
            'created_by' => $user->id,
        ]);
    }

    /** Tambah karton + isi (style×colorway×size×qty). */
    public function addCarton(PackingList $pl, array $carton, array $lines, User $user): Carton
    {
        if ($pl->status !== 'DRAFT') {
            throw new RuntimeException('Karton hanya bisa ditambah ke packing list DRAFT.');
        }
        if (empty($lines)) {
            throw new RuntimeException('Karton wajib punya isi.');
        }

        return DB::transaction(function () use ($pl, $carton, $lines, $user): Carton {
            $seq = (int) $pl->cartons()->max('seq') + 1;

            $c = $pl->cartons()->create([
                'company_id' => $pl->company_id,
                'carton_no' => $pl->doc_no.'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
                'seq' => $seq,
                'gross_weight_kg' => $carton['gross_weight_kg'] ?? null,
                'net_weight_kg' => $carton['net_weight_kg'] ?? null,
                'dimension' => $carton['dimension'] ?? null,
            ]);

            foreach ($lines as $line) {
                $c->lines()->create($line);
            }

            return $c->load('lines');
        });
    }

    /**
     * BR-021/082: ratio check — total per matrix (style×color×size) dari seluruh karton
     * dibanding SO lines; di luar toleransi → error berisi deviasi.
     */
    public function validateRatio(PackingList $pl): void
    {
        $so = $pl->salesOrder;
        $tolerance = (float) ($so->tolerance_pct ?? $so->customer?->shipment_tolerance_pct ?? 0);

        $packed = DB::table('carton_lines')
            ->join('cartons', 'cartons.id', '=', 'carton_lines.carton_id')
            ->where('cartons.packing_list_id', $pl->id)
            ->selectRaw('style_id, colorway_id, size_id, SUM(qty) as qty')
            ->groupBy('style_id', 'colorway_id', 'size_id')
            ->get()
            ->keyBy(fn ($r) => "{$r->style_id}-{$r->colorway_id}-{$r->size_id}");

        $violations = [];
        foreach ($so->lines as $soLine) {
            $key = "{$soLine->style_id}-{$soLine->colorway_id}-{$soLine->size_id}";
            $packedQty = (float) ($packed[$key]->qty ?? 0);
            $ordered = (float) $soLine->qty;
            $maxAllowed = $ordered * (1 + $tolerance / 100);

            if ($packedQty > $maxAllowed) {
                $violations[] = "Matrix {$key}: packed {$packedQty} > order {$ordered} + toleransi {$tolerance}%";
            }
        }

        if (! empty($violations)) {
            throw new RuntimeException("BR-021: ratio check gagal:\n- ".implode("\n- ", $violations));
        }
    }

    /**
     * BR-082: finalize — QC FINAL PASS wajib; FG masuk gudang FG via ITS PRODUCTION_RECEIPT.
     */
    public function finalize(PackingList $pl, int $fgWarehouseId, User $user): PackingList
    {
        if ($pl->status !== 'DRAFT') {
            throw new RuntimeException('Hanya packing list DRAFT yang bisa di-finalize.');
        }
        if ($pl->cartons()->count() === 0) {
            throw new RuntimeException('Packing list tanpa karton tidak bisa di-finalize.');
        }

        // BR-082: wajib QC FINAL PASS (bila ada MO)
        if ($pl->production_order_id) {
            $qcPass = QcInspection::where('production_order_id', $pl->production_order_id)
                ->where('stage', 'FINAL')
                ->where('verdict', 'PASS')
                ->exists();
            if (! $qcPass) {
                throw new RuntimeException('BR-082: belum ada QC FINAL PASS untuk MO ini — packing tidak bisa di-finalize.');
            }
        }

        $this->validateRatio($pl);   // BR-021/082

        return DB::transaction(function () use ($pl, $fgWarehouseId, $user): PackingList {
            // FG masuk gudang FG per matrix line (item_type FG, variant style×color×size)
            $itsLines = DB::table('carton_lines')
                ->join('cartons', 'cartons.id', '=', 'carton_lines.carton_id')
                ->where('cartons.packing_list_id', $pl->id)
                ->selectRaw('style_id, colorway_id, size_id, SUM(qty) as qty')
                ->groupBy('style_id', 'colorway_id', 'size_id')
                ->get()
                ->map(fn ($r) => [
                    'item_type' => 'FG',
                    'style_id' => $r->style_id,
                    'colorway_id' => $r->colorway_id,
                    'size_id' => $r->size_id,
                    'warehouse_id' => $fgWarehouseId,
                    'qty' => (float) $r->qty,
                    'uom_id' => \Modules\MasterData\Models\Uom::where('code', 'like', 'PCS%')->value('id') ?? 1,
                ])->all();

            $this->its->post('PRODUCTION_RECEIPT', [
                'company_id' => $pl->company_id,
                'source_document_type' => 'packing_lists',
                'source_document_id' => $pl->id,
            ], $itsLines, $user);

            $pl->update(['status' => 'APPROVED', 'updated_by' => $user->id]);

            // MO → PACKED (BR-012)
            if ($pl->production_order_id) {
                $mo = $pl->productionOrder;
                if (in_array($mo->status, ['QC', 'FINISHING', 'SEWING'], true)) {
                    $mo->update(['status' => 'PACKED']);
                }
            }

            $this->audit->record('update', $pl, after: ['status' => 'APPROVED', 'cartons' => $pl->cartons()->count()]);

            return $pl->fresh('cartons.lines');
        });
    }
}
