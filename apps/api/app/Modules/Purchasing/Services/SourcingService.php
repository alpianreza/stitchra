<?php

namespace Modules\Purchasing\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Core\Support\CurrentCompany;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\Quotation;
use Modules\Purchasing\Models\Rfq;
use RuntimeException;

/** PF-03: optional, manual sourcing. Award creates a PO DRAFT, never an approved PO. */
class SourcingService
{
    public function __construct(
        private NumberingService $numbering,
        private PurchasingService $purchasing,
        private AuditService $audit,
    ) {}

    public function createRfq(int $companyId, array $header, array $lines, array $supplierIds, User $user): Rfq
    {
        $this->authorize($companyId, $user);
        Validator::make(array_merge($header, ['lines' => $lines, 'supplier_ids' => $supplierIds]), [
            'deadline' => 'nullable|date', 'notes' => 'nullable|string|max:5000',
            'supplier_ids' => 'required|array|min:1|max:100', 'supplier_ids.*' => 'required|integer|distinct|min:1',
            'lines' => 'required|array|min:1|max:100', 'lines.*.material_id' => 'required|integer|min:1',
            'lines.*.uom_id' => 'required|integer|min:1', 'lines.*.qty' => 'required|numeric|min:0.0001',
            'lines.*.pr_line_id' => 'nullable|integer|min:1',
        ])->validate();

        return DB::transaction(function () use ($companyId, $header, $lines, $supplierIds, $user): Rfq {
            foreach ($supplierIds as $id) {
                $this->reference('suppliers', (int) $id, $companyId, true);
            }
            $rfq = Rfq::create([
                'company_id' => $companyId, 'doc_no' => $this->numbering->next($companyId, 'RFQ'),
                'deadline' => $header['deadline'] ?? null, 'notes' => $header['notes'] ?? null,
                'status' => 'OPEN', 'created_by' => $user->id,
            ]);
            $seen = [];
            foreach ($lines as $index => $line) {
                $this->reference('materials', (int) $line['material_id'], $companyId, true);
                $this->reference('uoms', (int) $line['uom_id'], $companyId);
                $key = implode(':', [$line['material_id'], $line['uom_id'], $line['pr_line_id'] ?? 'manual']);
                if (isset($seen[$key])) {
                    throw new RuntimeException('RFQ line material/UOM/source duplikat.');
                }
                $seen[$key] = true;
                if (! empty($line['pr_line_id'])) {
                    $prLine = DB::table('pr_lines')->join('purchase_requests as pr', 'pr.id', '=', 'pr_lines.purchase_request_id')
                        ->where('pr.company_id', $companyId)->where('pr.status', 'APPROVED')
                        ->where('pr_lines.id', $line['pr_line_id'])->select('pr_lines.*')->first();
                    if (! $prLine || (int) $prLine->material_id !== (int) $line['material_id']
                        || (int) $prLine->uom_id !== (int) $line['uom_id']
                        || round((float) $line['qty'], 4) > (float) $prLine->qty) {
                        throw new RuntimeException('RFQ harus cocok dengan material, UOM, dan qty PR APPROVED pada company aktif.');
                    }
                }
                $rfq->lines()->create([
                    'line_no' => $index + 1, 'material_id' => $line['material_id'],
                    'uom_id' => $line['uom_id'], 'qty' => round((float) $line['qty'], 4),
                    'pr_line_id' => $line['pr_line_id'] ?? null,
                ]);
            }
            $rfq->suppliers()->attach($supplierIds);
            $this->audit->record('create', $rfq, after: $rfq->load('lines', 'suppliers')->toArray());

            return $rfq;
        });
    }

    public function addQuotation(Rfq $rfq, array $data, User $user): Quotation
    {
        $this->authorize((int) $rfq->company_id, $user);
        Validator::make($data, [
            'supplier_id' => 'required|integer|min:1', 'currency_id' => 'required|integer|min:1',
            'exchange_rate' => 'required|numeric|min:0.000000000001',
            'quotation_no' => 'required|string|max:128', 'quoted_date' => 'required|date',
            'valid_until' => 'nullable|date|after_or_equal:quoted_date',
            'lead_time_days' => 'nullable|integer|min:0|max:3650', 'payment_term' => 'nullable|string|max:64',
            'lines' => 'required|array|min:1|max:100', 'lines.*.rfq_line_id' => 'required|integer|distinct|min:1',
            'lines.*.unit_price' => 'required|numeric|min:0',
        ])->validate();

        return DB::transaction(function () use ($rfq, $data, $user): Quotation {
            $locked = $this->lockRfq($rfq);
            if ($locked->status !== 'OPEN') {
                throw new RuntimeException('Quotation hanya dapat ditambahkan ke RFQ OPEN.');
            }
            $this->reference('suppliers', (int) $data['supplier_id'], (int) $locked->company_id, true);
            if (! $locked->suppliers()->where('suppliers.id', $data['supplier_id'])->exists()) {
                throw new RuntimeException('Supplier tidak termasuk penerima RFQ.');
            }
            $currency = $this->reference('currencies', (int) $data['currency_id'], (int) $locked->company_id);
            $base = DB::table('companies')->where('id', $locked->company_id)->value('base_currency');
            if ($currency->code === $base && abs((float) $data['exchange_rate'] - 1) > 0.000000000001) {
                throw new RuntimeException('Exchange rate base currency harus 1.');
            }
            $rfqLines = $locked->lines()->lockForUpdate()->get()->keyBy('id');
            if ($rfqLines->isEmpty() || $rfqLines->count() !== count($data['lines'])) {
                throw new RuntimeException('Quotation harus mencakup seluruh RFQ line; split award belum didukung.');
            }
            $quotation = $locked->quotations()->create([
                'supplier_id' => $data['supplier_id'], 'currency_id' => $currency->id, 'currency' => $currency->code,
                'exchange_rate' => $data['exchange_rate'], 'base_currency' => $base,
                'quotation_no' => trim($data['quotation_no']), 'quoted_date' => $data['quoted_date'],
                'valid_until' => $data['valid_until'] ?? null, 'lead_time_days' => $data['lead_time_days'] ?? null,
                'payment_term' => $data['payment_term'] ?? null, 'is_selected' => false, 'created_by' => $user->id,
            ]);
            foreach ($data['lines'] as $line) {
                $source = $rfqLines->get((int) $line['rfq_line_id']);
                if (! $source) {
                    throw new RuntimeException('Quotation line tidak berasal dari RFQ ini.');
                }
                $quotation->lines()->create([
                    'rfq_line_id' => $source->id, 'material_id' => $source->material_id,
                    'qty' => $source->qty, 'uom_id' => $source->uom_id, 'unit_price' => round((float) $line['unit_price'], 6),
                ]);
            }
            $this->audit->record('add_quotation', $locked, after: ['quotation' => $quotation->load('lines')->toArray()]);

            return $quotation->load('supplier');
        });
    }

    public function comparison(Rfq $rfq, User $user): Rfq
    {
        $this->authorize((int) $rfq->company_id, $user);
        $rfq = Rfq::withoutGlobalScopes()->where('company_id', $rfq->company_id)->findOrFail($rfq->id);
        $rfq->load('lines.material', 'lines.uom', 'lines.prLine.purchaseRequest', 'suppliers', 'quotations.supplier', 'quotations.lines', 'purchaseOrder');
        $base = DB::table('companies')->where('id', $rfq->company_id)->value('base_currency');
        foreach ($rfq->quotations as $quotation) {
            $total = round($quotation->lines->sum(fn ($line) => (float) $line->qty * (float) $line->unit_price), 4);
            $quotation->setAttribute('total_amount', $total);
            $quotation->setAttribute('base_total', $quotation->base_currency === $base && (float) $quotation->exchange_rate > 0
                ? round($total * (float) $quotation->exchange_rate, 4) : null);
        }
        $rfq->setAttribute('comparison_base_currency', $base);

        return $rfq;
    }

    public function award(Rfq $rfq, int $quotationId, array $data, User $user): PurchaseOrder
    {
        $this->authorize((int) $rfq->company_id, $user);
        Validator::make($data, [
            'order_date' => 'required|date', 'expected_date' => 'nullable|date|after_or_equal:order_date',
            'reason' => 'required|string|max:5000',
        ])->validate();
        if (trim($data['reason']) === '') {
            throw new RuntimeException('Alasan pemilihan supplier wajib diisi.');
        }

        return DB::transaction(function () use ($rfq, $quotationId, $data, $user): PurchaseOrder {
            $locked = $this->lockRfq($rfq);
            $quotation = $locked->quotations()->whereKey($quotationId)->lockForUpdate()->first();
            if (! $quotation) {
                throw new RuntimeException('Quotation tidak berasal dari RFQ ini.');
            }
            $expectedDate = ! empty($data['expected_date']) ? Carbon::parse($data['expected_date'])->toDateString()
                : ($quotation->lead_time_days === null ? null : Carbon::parse($data['order_date'])->addDays($quotation->lead_time_days)->toDateString());
            $orderDate = Carbon::parse($data['order_date'])->toDateString();
            $existing = $locked->purchaseOrder()->withoutGlobalScopes()->where('company_id', $locked->company_id)->first();
            if ($existing) {
                if ($locked->status !== 'AWARDED' || (int) $existing->quotation_id !== $quotationId
                    || $existing->order_date->toDateString() !== $orderDate || $existing->expected_date?->toDateString() !== $expectedDate
                    || $locked->award_reason !== trim($data['reason'])) {
                    throw new RuntimeException('RFQ sudah di-award dengan pilihan atau payload berbeda.');
                }

                return $existing->load('lines');
            }
            if (! in_array($locked->status, ['OPEN', 'CLOSED'], true)) {
                throw new RuntimeException('RFQ tidak dapat di-award.');
            }
            $currency = $quotation->currency_id
                ? $this->reference('currencies', (int) $quotation->currency_id, (int) $locked->company_id) : null;
            $base = DB::table('companies')->where('id', $locked->company_id)->value('base_currency');
            if (! $currency || $currency->code !== $quotation->currency || $quotation->base_currency !== $base
                || (float) $quotation->exchange_rate <= 0 || ! $quotation->quoted_date) {
                throw new RuntimeException('Snapshot currency/rate quotation tidak lengkap; quotation legacy tidak boleh ditebak.');
            }
            if ($orderDate < $quotation->quoted_date->toDateString()
                || ($quotation->valid_until && $orderDate > $quotation->valid_until->toDateString())) {
                throw new RuntimeException('Tanggal PO di luar masa berlaku quotation.');
            }
            $this->reference('suppliers', (int) $quotation->supplier_id, (int) $locked->company_id, true);
            if (! $locked->suppliers()->where('suppliers.id', $quotation->supplier_id)->exists()) {
                throw new RuntimeException('Supplier quotation bukan penerima RFQ.');
            }
            $rfqLines = $locked->lines()->lockForUpdate()->get()->keyBy('id');
            $quoteLines = $quotation->lines()->orderBy('id')->lockForUpdate()->get();
            if ($rfqLines->isEmpty() || $rfqLines->count() !== $quoteLines->count()
                || $quoteLines->pluck('rfq_line_id')->unique()->count() !== $rfqLines->count()) {
                throw new RuntimeException('Provenance quotation/RFQ line tidak lengkap.');
            }
            $lines = [];
            foreach ($quoteLines as $line) {
                $source = $rfqLines->get($line->rfq_line_id);
                if (! $source || (int) $source->material_id !== (int) $line->material_id
                    || (int) $source->uom_id !== (int) $line->uom_id || $source->qty !== $line->qty) {
                    throw new RuntimeException('Material, UOM, atau quantity quotation berbeda dari RFQ.');
                }
                $lines[] = [
                    'material_id' => $line->material_id, 'uom_id' => $line->uom_id,
                    'qty' => $line->qty, 'unit_price' => $line->unit_price,
                    'pr_line_id' => $source->pr_line_id, 'quotation_line_id' => $line->id,
                ];
            }
            $po = $this->purchasing->createPo((int) $locked->company_id, [
                'supplier_id' => $quotation->supplier_id, 'currency_id' => $quotation->currency_id,
                'exchange_rate' => $quotation->exchange_rate, 'payment_term' => $quotation->payment_term,
                'order_date' => $orderDate, 'expected_date' => $expectedDate,
            ], $lines, $user);
            $po->update(['rfq_id' => $locked->id, 'quotation_id' => $quotation->id]);
            foreach ($po->lines as $index => $poLine) {
                $poLine->update(['quotation_line_id' => $quoteLines[$index]->id]);
            }
            $quotation->update(['is_selected' => true]);
            $locked->update(['status' => 'AWARDED', 'award_reason' => trim($data['reason']), 'updated_by' => $user->id]);
            $this->audit->record('award', $locked, after: [
                'quotation_id' => $quotation->id, 'purchase_order_id' => $po->id, 'reason' => $locked->award_reason,
            ]);
            $this->audit->record('create', $po, after: $po->toArray());

            return $po;
        }, 3);
    }

    public function close(Rfq $rfq, User $user): Rfq
    {
        $this->authorize((int) $rfq->company_id, $user);

        return DB::transaction(function () use ($rfq, $user): Rfq {
            $locked = $this->lockRfq($rfq);
            if ($locked->status === 'CLOSED') {
                return $locked;
            }
            if ($locked->status !== 'OPEN') {
                throw new RuntimeException('Hanya RFQ OPEN yang bisa ditutup.');
            }
            $locked->update(['status' => 'CLOSED', 'updated_by' => $user->id]);
            $this->audit->record('close', $locked, after: ['status' => 'CLOSED']);

            return $locked;
        });
    }

    private function lockRfq(Rfq $rfq): Rfq
    {
        return Rfq::withoutGlobalScopes()->where('company_id', $rfq->company_id)->whereKey($rfq->id)->lockForUpdate()->firstOrFail();
    }

    private function reference(string $table, int $id, int $companyId, bool $active = false): object
    {
        $query = DB::table($table)->where('company_id', $companyId)->where('id', $id);
        if ($active) {
            $query->where('is_active', true)->whereNull('deleted_at');
        }
        $row = $query->first();
        if (! $row) {
            throw new RuntimeException("Referensi {$table} tidak ditemukan/aktif pada company dokumen.");
        }

        return $row;
    }

    private function authorize(int $companyId, User $user): void
    {
        if ((CurrentCompany::id() !== null && CurrentCompany::id() !== $companyId)
            || ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists())) {
            throw new RuntimeException('User tidak memiliki akses ke company dokumen.');
        }
    }
}
