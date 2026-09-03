<?php

namespace Modules\Packing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\ApprovalRequest;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Uom;
use Modules\Packing\Models\Carton;
use Modules\Packing\Models\PackingList;
use Modules\Packing\Models\PackingSourceAttachment;
use Modules\Production\Models\ProductionOrder;
use Modules\Qc\Models\QcInspection;
use Modules\Sales\Models\SalesOrder;
use RuntimeException;

class PackingService
{
    public const LEGACY_SOURCE_APPROVAL_DOC_TYPE = 'PACKING_QC_SOURCE';

    public function __construct(
        private NumberingService $numbering,
        private InventoryTransactionService $its,
        private AuditService $audit,
        private ApprovalEngine $approval,
    ) {}

    public function eligiblePackingInputs(int $companyId): array
    {
        $latestFinals = QcInspection::withoutGlobalScopes()->with(['productionOrder.salesOrder', 'productionOrder.style'])
            ->where('company_id', $companyId)->where('stage', 'FINAL')->orderByDesc('cycle')->orderByDesc('id')->get();
        $seen = []; $eligible = [];
        foreach ($latestFinals as $inspection) {
            $mo = $inspection->productionOrder;
            if ($mo === null || isset($seen[$mo->id])) continue;
            $seen[$mo->id] = true;
            if ($inspection->verdict !== 'PASS' || $mo->status !== 'QC') continue;
            $packed = $this->packedQuantityForMo((int) $mo->id); $remaining = max(0.0, (float) $inspection->lot_qty - $packed); if ($remaining <= 0.0001) continue;
            $eligible[] = ['qc_inspection_id' => $inspection->id, 'qc_doc_no' => $inspection->doc_no, 'qc_stage' => $inspection->stage, 'qc_verdict' => $inspection->verdict,
                'qc_cycle' => $inspection->cycle, 'eligible_qty' => (float) $inspection->lot_qty, 'packed_qty' => $packed, 'remaining_qty' => $remaining,
                'production_order_id' => $mo->id, 'production_order_no' => $mo->doc_no, 'production_order_status' => $mo->status,
                'sales_order_id' => $mo->sales_order_id, 'sales_order_no' => $mo->salesOrder?->doc_no, 'style_no' => $mo->style?->style_no]; }
        return $eligible;
    }

    /** BR-068: show only an explicitly selectable source; never attach it implicitly. */
    public function legacySourceCandidates(PackingList $packingList, User $user): array
    {
        $loaded = PackingList::withoutGlobalScopes()->whereKey($packingList->id)->firstOrFail();
        $this->assertAccess($user, (int) $loaded->company_id);
        if ($loaded->qc_inspection_id !== null || $loaded->production_order_id === null) return [];
        $latest = QcInspection::withoutGlobalScopes()->where('company_id', $loaded->company_id)
            ->where('production_order_id', $loaded->production_order_id)->where('stage', 'FINAL')
            ->orderByDesc('cycle')->orderByDesc('id')->first();
        if ($latest === null || $latest->verdict !== 'PASS') return [];
        $this->assertLegacySourceEvidence($loaded, $latest);
        return [[
            'qc_inspection_id' => $latest->id,
            'qc_doc_no' => $latest->doc_no,
            'qc_cycle' => (int) $latest->cycle,
            'qc_verdict' => $latest->verdict,
            'lot_qty' => (float) $latest->lot_qty,
            'production_order_id' => (int) $latest->production_order_id,
        ]];
    }

    /** BR-068: persist a reasoned candidate, then submit that immutable proposal to ApprovalEngine. */
    public function requestLegacySourceAttachment(PackingList $packingList, QcInspection $source, string $reason, User $user): PackingSourceAttachment
    {
        $reason = trim($reason);
        if ($reason === '') throw new RuntimeException('BR-068: reason attachment source QC wajib diisi.');

        return DB::transaction(function () use ($packingList, $source, $reason, $user): PackingSourceAttachment {
            $locked = PackingList::withoutGlobalScopes()->whereKey($packingList->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($user, (int) $locked->company_id);
            if ($locked->qc_inspection_id !== null) throw new RuntimeException('BR-068: Packing List sudah memiliki source QC.');
            $qc = QcInspection::withoutGlobalScopes()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $this->assertLegacySourceEvidence($locked, $qc);

            $pending = PackingSourceAttachment::withoutGlobalScopes()->with('approvalRequest')
                ->where('company_id', $locked->company_id)->where('packing_list_id', $locked->id)
                ->whereHas('approvalRequest', fn ($query) => $query->where('status', 'PENDING')->where('is_active', true))
                ->latest('id')->first();
            if ($pending !== null) {
                if ((int) $pending->qc_inspection_id === (int) $qc->id && $pending->reason === $reason) return $pending;
                throw new RuntimeException('BR-068: attachment source lain masih menunggu approval.');
            }

            $attachment = PackingSourceAttachment::create([
                'company_id' => $locked->company_id,
                'packing_list_id' => $locked->id,
                'qc_inspection_id' => $qc->id,
                'reason' => $reason,
                'requested_by' => $user->id,
            ]);
            $approval = $this->approval->submit($attachment, self::LEGACY_SOURCE_APPROVAL_DOC_TYPE, $user);
            $attachment->update(['approval_request_id' => $approval->id]);
            $this->audit->record('submit', $attachment, after: [
                'packing_list_id' => $locked->id,
                'qc_inspection_id' => $qc->id,
                'reason' => $reason,
                'approval_request_id' => $approval->id,
                'policy' => 'BR-068:READ_ONLY_UNTIL_APPROVED_SOURCE_ATTACHMENT',
            ]);
            return $attachment->fresh(['qcInspection', 'approvalRequest', 'requester']);
        });
    }

    /** BR-068: approved application is explicit and revalidates all evidence under locks. */
    public function applyLegacySourceAttachment(PackingSourceAttachment $attachment, User $user): PackingList
    {
        return DB::transaction(function () use ($attachment, $user): PackingList {
            $proposal = PackingSourceAttachment::withoutGlobalScopes()->whereKey($attachment->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($user, (int) $proposal->company_id);
            $locked = PackingList::withoutGlobalScopes()->where('company_id', $proposal->company_id)
                ->whereKey($proposal->packing_list_id)->lockForUpdate()->firstOrFail();

            if ($proposal->applied_at !== null) {
                if ((int) $locked->qc_inspection_id !== (int) $proposal->qc_inspection_id) throw new RuntimeException('BR-068: applied attachment tidak sesuai source Packing List.');
                return $locked->fresh(['qcInspection', 'sourceAttachments.approvalRequest']);
            }
            if ($locked->qc_inspection_id !== null) throw new RuntimeException('BR-068: source Packing List sudah berubah; attachment ditolak.');

            $approval = ApprovalRequest::withoutGlobalScopes()->where('company_id', $proposal->company_id)
                ->whereKey($proposal->approval_request_id)->lockForUpdate()->first();
            if ($approval === null || $approval->doc_type !== self::LEGACY_SOURCE_APPROVAL_DOC_TYPE
                || (int) $approval->doc_id !== (int) $proposal->id || $approval->status !== 'APPROVED' || $approval->is_active) {
                throw new RuntimeException('BR-068: attachment source belum disetujui ApprovalEngine.');
            }

            $qc = QcInspection::withoutGlobalScopes()->where('company_id', $proposal->company_id)
                ->whereKey($proposal->qc_inspection_id)->lockForUpdate()->firstOrFail();
            $this->assertLegacySourceEvidence($locked, $qc);
            $locked->update(['qc_inspection_id' => $qc->id, 'updated_by' => $user->id]);
            $proposal->update(['applied_by' => $user->id, 'applied_at' => now()]);
            $this->audit->record('update', $locked, after: [
                'qc_inspection_id' => $qc->id,
                'source_attachment_id' => $proposal->id,
                'approval_request_id' => $approval->id,
                'reason' => $proposal->reason,
                'applied_by' => $user->id,
                'policy' => 'BR-068:APPROVED_SOURCE_ATTACHMENT',
            ]);
            return $locked->fresh(['qcInspection', 'sourceAttachments.approvalRequest']);
        });
    }

    public function create(SalesOrder $so, ?int $moId, User $user): PackingList
    {
        return DB::transaction(function () use ($so, $moId, $user): PackingList {
            $locked = SalesOrder::withoutGlobalScopes()->whereKey($so->id)->lockForUpdate()->firstOrFail(); $this->assertAccess($user, (int) $locked->company_id);
            if (! in_array($locked->status, ['CONFIRMED', 'IN_PROGRESS'], true)) throw new RuntimeException('Packing list hanya untuk SO CONFIRMED/IN_PROGRESS.');
            if ($moId === null) throw new RuntimeException('BR-080: MO wajib dipilih agar source Packing dapat ditelusuri ke QC FINAL.');
            $mo = ProductionOrder::withoutGlobalScopes()->where('company_id', $locked->company_id)->where('sales_order_id', $locked->id)->whereKey($moId)->lockForUpdate()->first();
            if ($mo === null) throw new RuntimeException('MO packing bukan milik SO/company ini.');
            $latest = QcInspection::withoutGlobalScopes()->where('company_id', $locked->company_id)->where('production_order_id', $mo->id)
                ->where('stage', 'FINAL')->orderByDesc('cycle')->orderByDesc('id')->lockForUpdate()->first();
            if ($mo->status !== 'QC' || $latest === null || $latest->verdict !== 'PASS') {
                throw new RuntimeException('BR-080: Packing List baru wajib memiliki cycle QC FINAL terbaru yang PASS.');
            }
            $created = PackingList::create(['company_id' => $locked->company_id, 'doc_no' => $this->numbering->next($locked->company_id, 'PL'),
                'sales_order_id' => $locked->id, 'production_order_id' => $mo->id, 'qc_inspection_id' => $latest->id,
                'status' => 'DRAFT', 'created_by' => $user->id]);
            $this->audit->record('create', $created, after: ['production_order_id' => $mo->id, 'qc_inspection_id' => $created->qc_inspection_id]);
            return $created->fresh(['salesOrder.customer', 'productionOrder', 'qcInspection']);
        });
    }

    public function addCarton(PackingList $packingList, array $carton, array $lines, User $user): Carton
    {
        if ($lines === []) throw new RuntimeException('Karton wajib punya isi.');
        return DB::transaction(function () use ($packingList, $carton, $lines, $user): Carton {
            $locked = PackingList::withoutGlobalScopes()->whereKey($packingList->id)->lockForUpdate()->firstOrFail(); $this->assertAccess($user, (int) $locked->company_id);
            if ($locked->status !== 'DRAFT') throw new RuntimeException('Karton hanya bisa ditambah ke packing list DRAFT.');
            $so = SalesOrder::withoutGlobalScopes()->with('lines', 'customer')->where('company_id', $locked->company_id)->whereKey($locked->sales_order_id)->lockForUpdate()->firstOrFail();
            $mo = $locked->production_order_id ? ProductionOrder::withoutGlobalScopes()->where('company_id', $locked->company_id)->where('sales_order_id', $so->id)->whereKey($locked->production_order_id)->lockForUpdate()->first() : null;
            if ($mo === null) throw new RuntimeException('BR-080: Packing Input wajib memiliki source MO dan QC FINAL PASS.');
            $pass = $this->assertPackingInput($locked, $mo);
            $seen = []; $incomingTotal = 0.0; $incomingByMatrix = [];
            foreach ($lines as $line) { $qty = (float) ($line['qty'] ?? 0); $key = ((int) $line['style_id']).'-'.((int) $line['colorway_id']).'-'.((int) $line['size_id']);
                if ($qty <= 0) throw new RuntimeException('Qty carton wajib > 0.'); if (isset($seen[$key])) throw new RuntimeException('Matrix carton tidak boleh duplikat.'); $seen[$key] = true;
                if (! DB::table('sales_order_lines')->where('sales_order_id', $locked->sales_order_id)->where('style_id', $line['style_id'])->where('colorway_id', $line['colorway_id'])->where('size_id', $line['size_id'])->exists()) throw new RuntimeException('Matrix carton tidak terdapat pada SO.');
                if ((int) $mo->style_id !== (int) $line['style_id']) throw new RuntimeException('Style carton tidak sesuai MO packing.');
                $incomingTotal += $qty; $incomingByMatrix[$key] = ($incomingByMatrix[$key] ?? 0.0) + $qty; }
            PackingList::withoutGlobalScopes()->where('production_order_id', $mo->id)->where('status', '!=', 'CANCELLED')->lockForUpdate()->get();
            if ($this->packedQuantityForMo((int) $mo->id) + $incomingTotal - (float) $pass->lot_qty > 0.0001) throw new RuntimeException('BR-080: cumulative carton quantity melebihi quantity QC FINAL PASS yang eligible.');
            $tolerance = (float) ($so->tolerance_pct ?? $so->customer?->shipment_tolerance_pct ?? 0);
            foreach ($incomingByMatrix as $key => $incomingQty) { [$styleId, $colorwayId, $sizeId] = array_map('intval', explode('-', $key));
                $orderLine = $so->lines->first(fn ($line) => (int) $line->style_id === $styleId && (int) $line->colorway_id === $colorwayId && (int) $line->size_id === $sizeId);
                $allocated = $this->packedQuantityForMatrix((int) $mo->id, $styleId, $colorwayId, $sizeId);
                if ($orderLine === null || $allocated + $incomingQty - (float) $orderLine->qty * (1 + $tolerance / 100) > 0.0001) throw new RuntimeException('BR-021: cumulative carton matrix melebihi SO+toleransi.'); }
            $gross = $carton['gross_weight_kg'] ?? null; $net = $carton['net_weight_kg'] ?? null;
            if (($gross !== null && (float) $gross < 0) || ($net !== null && (float) $net < 0) || ($gross !== null && $net !== null && (float) $net > (float) $gross)) throw new RuntimeException('Berat carton tidak valid.');
            $sequence = (int) $locked->cartons()->max('seq') + 1;
            $created = $locked->cartons()->create(['company_id' => $locked->company_id, 'carton_no' => $locked->doc_no.'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
                'seq' => $sequence, 'gross_weight_kg' => $gross, 'net_weight_kg' => $net, 'dimension' => $carton['dimension'] ?? null]);
            foreach ($lines as $line) $created->lines()->create($line);
            $this->audit->record('create', $created, after: ['packing_list_id' => $locked->id, 'qc_inspection_id' => $pass->id, 'qty' => $incomingTotal]);
            return $created->load('lines');
        });
    }

    public function finalize(PackingList $packingList, int $warehouseId, User $user): PackingList
    {
        return DB::transaction(function () use ($packingList, $warehouseId, $user): PackingList {
            $locked = PackingList::withoutGlobalScopes()->whereKey($packingList->id)->lockForUpdate()->firstOrFail(); $this->assertAccess($user, (int) $locked->company_id);
            if ($locked->status !== 'DRAFT' || ! $locked->cartons()->exists()) throw new RuntimeException('Packing list harus DRAFT dan memiliki karton.');
            $so = SalesOrder::withoutGlobalScopes()->with('lines', 'customer')->where('company_id', $locked->company_id)->whereKey($locked->sales_order_id)->lockForUpdate()->firstOrFail();
            if (! $locked->production_order_id) throw new RuntimeException('BR-080: Packing List tanpa source MO/QC tidak dapat difinalisasi.');
            $mo = ProductionOrder::withoutGlobalScopes()->where('company_id', $locked->company_id)->where('sales_order_id', $so->id)->whereKey($locked->production_order_id)->lockForUpdate()->firstOrFail();
            $pass = $this->assertPackingInput($locked, $mo);
            if (! DB::table('warehouses')->where('company_id', $locked->company_id)->where('type', 'FG')->where('id', $warehouseId)->exists()) throw new RuntimeException('Warehouse finalize wajib warehouse FG pada company yang sama.');
            $pcs = Uom::withoutGlobalScopes()->where('company_id', $locked->company_id)->where('code', 'PCS')->first(); if ($pcs === null) throw new RuntimeException('PCS UOM belum dikonfigurasi pada company ini.');
            $packed = DB::table('carton_lines')->join('cartons', 'cartons.id', '=', 'carton_lines.carton_id')->where('cartons.packing_list_id', $locked->id)
                ->selectRaw('style_id,colorway_id,size_id,SUM(qty) qty')->groupBy('style_id', 'colorway_id', 'size_id')->get();
            if ($this->packedQuantityForMo((int) $mo->id) - (float) $pass->lot_qty > 0.0001) throw new RuntimeException('BR-080: packed quantity melebihi source QC FINAL PASS.');
            $tolerance = (float) ($so->tolerance_pct ?? $so->customer?->shipment_tolerance_pct ?? 0);
            foreach ($packed as $row) { $ordered = (float) $so->lines->first(fn ($line) => (int) $line->style_id === (int) $row->style_id && (int) $line->colorway_id === (int) $row->colorway_id && (int) $line->size_id === (int) $row->size_id)?->qty;
                $prior = (float) DB::table('carton_lines')->join('cartons', 'cartons.id', '=', 'carton_lines.carton_id')->join('packing_lists', 'packing_lists.id', '=', 'cartons.packing_list_id')
                    ->where('packing_lists.sales_order_id', $so->id)->where('packing_lists.status', 'APPROVED')->where('carton_lines.style_id', $row->style_id)
                    ->where('carton_lines.colorway_id', $row->colorway_id)->where('carton_lines.size_id', $row->size_id)->sum('carton_lines.qty');
                if ($ordered <= 0 || $prior + (float) $row->qty - $ordered * (1 + $tolerance / 100) > 0.0001) throw new RuntimeException('BR-021: cumulative packed quantity melebihi SO+toleransi.'); }
            $itsLines = $packed->map(fn ($row) => ['item_type' => 'FG', 'style_id' => $row->style_id, 'colorway_id' => $row->colorway_id,
                'size_id' => $row->size_id, 'warehouse_id' => $warehouseId, 'qty' => (float) $row->qty, 'uom_id' => $pcs->id])->all();
            $this->its->post('PRODUCTION_RECEIPT', ['company_id' => $locked->company_id, 'source_document_type' => 'packing_lists', 'source_document_id' => $locked->id], $itsLines, $user);
            $locked->update(['status' => 'APPROVED', 'updated_by' => $user->id]);
            $packedQty = $this->packedQuantityForMo((int) $mo->id);
            if ($packedQty + 0.0001 >= (float) $pass->lot_qty) $mo->update(['status' => 'PACKED', 'updated_by' => $user->id]);
            $this->audit->record('update', $locked, after: ['status' => 'APPROVED', 'cartons' => $locked->cartons()->count(),
                'qc_inspection_id' => $pass->id, 'production_measure' => 'BR-065:PACKED_QTY', 'eligible_measure' => 'BR-065:QC_FINAL_PASS']);
            return $locked->fresh(['cartons.lines', 'qcInspection', 'productionOrder']);
        });
    }

    public function lineage(PackingList $packingList, User $user): array
    {
        $loaded = PackingList::withoutGlobalScopes()->with(['salesOrder.customer', 'productionOrder', 'qcInspection', 'cartons.lines', 'shipment.lines'])->whereKey($packingList->id)->firstOrFail();
        $this->assertAccess($user, (int) $loaded->company_id);
        $receipt = DB::table('stock_movements')->where('company_id', $loaded->company_id)->where('movement_type', 'PRODUCTION_RECEIPT')
            ->where('source_document_type', 'packing_lists')->where('source_document_id', $loaded->id)->first();
        $attachment = PackingSourceAttachment::withoutGlobalScopes()->with(['qcInspection', 'approvalRequest', 'requester', 'appliedBy'])
            ->where('company_id', $loaded->company_id)->where('packing_list_id', $loaded->id)->latest('id')->first();
        $missingSource = $loaded->qcInspection === null;
        return ['packing_list' => ['id' => $loaded->id, 'doc_no' => $loaded->doc_no, 'status' => $loaded->status, 'read_only' => $missingSource],
            'sales_order' => $loaded->salesOrder ? ['id' => $loaded->salesOrder->id, 'doc_no' => $loaded->salesOrder->doc_no] : null,
            'production_order' => $loaded->productionOrder ? ['id' => $loaded->productionOrder->id, 'doc_no' => $loaded->productionOrder->doc_no, 'status' => $loaded->productionOrder->status] : null,
            'packing_input' => $loaded->qcInspection ? ['source_type' => 'QC_FINAL_INSPECTION', 'measure' => 'QC_FINAL_PASS', 'id' => $loaded->qcInspection->id,
                'doc_no' => $loaded->qcInspection->doc_no, 'verdict' => $loaded->qcInspection->verdict, 'lot_qty' => (float) $loaded->qcInspection->lot_qty]
                : ['source_type' => 'QC_FINAL_INSPECTION', 'status' => 'MISSING_LEGACY_SOURCE', 'read_only' => true, 'automatic_attachment' => false],
            'source_attachment_control' => $attachment ? ['id' => $attachment->id, 'qc_inspection_id' => $attachment->qc_inspection_id,
                'qc_doc_no' => $attachment->qcInspection?->doc_no, 'reason' => $attachment->reason, 'requested_by' => $attachment->requester?->name,
                'approval_request_id' => $attachment->approval_request_id, 'approval_status' => $attachment->approvalRequest?->status,
                'applied_by' => $attachment->appliedBy?->name, 'applied_at' => $attachment->applied_at?->toIso8601String()]
                : ['status' => $missingSource ? 'NOT_REQUESTED' : 'NOT_REQUIRED', 'policy' => 'EXPLICIT_APPROVED_AUDITED_ONLY'],
            'cartons' => $loaded->cartons->map(fn ($carton) => ['id' => $carton->id, 'carton_no' => $carton->carton_no, 'qty' => (float) $carton->lines->sum('qty'), 'lines' => $carton->lines])->values(),
            'carton_allocation' => ['matrix_supported' => true, 'direct_bundle_or_finishing_output_link' => false, 'authority' => 'NOT_DEFINED'],
            'fg_boundary' => ['defined_by' => 'PF-09/BR-013', 'production_measure' => 'FG_RECEIVED_QTY', 'production_receipt_posted' => $receipt !== null,
                'stock_movement_id' => $receipt?->id, 'status' => $missingSource ? 'BLOCKED_MISSING_LEGACY_SOURCE' : ($receipt !== null ? 'FG_RECEIVED' : 'PENDING_PACKING_FINALIZE')],
            'shipment_boundary' => $loaded->shipment ? ['id' => $loaded->shipment->id, 'doc_no' => $loaded->shipment->doc_no, 'status' => $loaded->shipment->status]
                : ['status' => $missingSource ? 'BLOCKED_MISSING_LEGACY_SOURCE' : 'NOT_CREATED', 'automatic_creation' => false]];
    }

    private function assertPackingInput(PackingList $packingList, ProductionOrder $mo): QcInspection
    {
        if ($packingList->qc_inspection_id === null) {
            throw new RuntimeException('BR-068: MISSING_LEGACY_SOURCE bersifat read-only sampai source attachment disetujui dan diterapkan.');
        }
        if ($mo->status !== 'QC') throw new RuntimeException('BR-080: MO wajib berstatus QC setelah QC FINAL PASS sebelum Packing.');
        $latest = QcInspection::withoutGlobalScopes()->where('company_id', $packingList->company_id)->where('production_order_id', $mo->id)
            ->where('stage', 'FINAL')->orderByDesc('cycle')->orderByDesc('id')->lockForUpdate()->first();
        if ($latest === null || $latest->verdict !== 'PASS') throw new RuntimeException('BR-080: cycle QC FINAL terbaru bukan PASS; Packing Input tidak tersedia.');
        if ((int) $packingList->qc_inspection_id !== (int) $latest->id) throw new RuntimeException('BR-080: source QC FINAL Packing sudah stale terhadap cycle terbaru.');
        return $latest;
    }

    private function assertLegacySourceEvidence(PackingList $packingList, QcInspection $source): void
    {
        if ($packingList->qc_inspection_id !== null) throw new RuntimeException('BR-068: source QC Packing List sudah terisi.');
        if ($packingList->production_order_id === null || $packingList->sales_order_id === null) throw new RuntimeException('BR-068: evidence MO/SO legacy tidak lengkap.');
        $mo = ProductionOrder::withoutGlobalScopes()->where('company_id', $packingList->company_id)
            ->where('sales_order_id', $packingList->sales_order_id)->whereKey($packingList->production_order_id)->first();
        if ($mo === null || (int) $source->company_id !== (int) $packingList->company_id
            || (int) $source->production_order_id !== (int) $mo->id || $source->stage !== 'FINAL' || $source->verdict !== 'PASS') {
            throw new RuntimeException('BR-068: source wajib exact same company/SO/MO dan QC FINAL PASS.');
        }
        $latest = QcInspection::withoutGlobalScopes()->where('company_id', $packingList->company_id)
            ->where('production_order_id', $mo->id)->where('stage', 'FINAL')
            ->orderByDesc('cycle')->orderByDesc('id')->first();
        if ($latest === null || (int) $latest->id !== (int) $source->id || $latest->verdict !== 'PASS') {
            throw new RuntimeException('BR-068/BR-080: source bukan cycle QC FINAL terbaru yang PASS.');
        }
        $firstCartonAt = DB::table('cartons')->where('packing_list_id', $packingList->id)->min('created_at');
        if ($firstCartonAt !== null && ($source->updated_at === null || $source->updated_at->greaterThan($firstCartonAt))) {
            throw new RuntimeException('BR-068: chronology QC FINAL PASS dan Carton tidak plausible.');
        }
        $cartonQty = (float) DB::table('carton_lines')->join('cartons', 'cartons.id', '=', 'carton_lines.carton_id')
            ->where('cartons.packing_list_id', $packingList->id)->sum('carton_lines.qty');
        if ($cartonQty - (float) $source->lot_qty > 0.0001) throw new RuntimeException('BR-068: quantity Carton melebihi PASS lot quantity.');
        $hasItsFact = DB::table('stock_movements')->where('company_id', $packingList->company_id)
            ->where('source_document_type', 'packing_lists')->where('source_document_id', $packingList->id)->exists()
            || DB::table('stock_ledger')->where('company_id', $packingList->company_id)
                ->where('source_document_type', 'packing_lists')->where('source_document_id', $packingList->id)->exists();
        $hasShipment = DB::table('shipments')->where('company_id', $packingList->company_id)->where('packing_list_id', $packingList->id)->exists();
        if (in_array($packingList->status, ['APPROVED', 'SHIPPED', 'CANCELLED'], true) || $hasItsFact || $hasShipment) {
            throw new RuntimeException('BR-068: downstream status/ITS/Shipment conflict; attachment ditolak.');
        }
    }

    private function packedQuantityForMo(int $moId): float
    {
        return (float) DB::table('carton_lines')->join('cartons', 'cartons.id', '=', 'carton_lines.carton_id')->join('packing_lists', 'packing_lists.id', '=', 'cartons.packing_list_id')
            ->where('packing_lists.production_order_id', $moId)->where('packing_lists.status', '!=', 'CANCELLED')->sum('carton_lines.qty');
    }

    private function packedQuantityForMatrix(int $moId, int $styleId, int $colorwayId, int $sizeId): float
    {
        return (float) DB::table('carton_lines')->join('cartons', 'cartons.id', '=', 'carton_lines.carton_id')->join('packing_lists', 'packing_lists.id', '=', 'cartons.packing_list_id')
            ->where('packing_lists.production_order_id', $moId)->where('packing_lists.status', '!=', 'CANCELLED')->where('carton_lines.style_id', $styleId)
            ->where('carton_lines.colorway_id', $colorwayId)->where('carton_lines.size_id', $sizeId)->sum('carton_lines.qty');
    }

    private function assertAccess(User $user, int $companyId): void
    {
        if ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists()) throw new RuntimeException('User tidak memiliki akses ke company packing.');
    }
}
