<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Finance\Models\AccountMapping;
use Modules\Finance\Models\Journal;
use Modules\Receiving\Models\GoodsReceipt;
use RuntimeException;

/**
 * Safe operational-event boundary for BR-101.
 *
 * Only events with an explicit source amount, source date, base-currency
 * authority, and configured account mapping may post. Undefined production,
 * WIP, FG, COGS, FX, and subcontract boundaries remain blocked.
 */
class OperationalPostingService
{
    public function __construct(private GlPostingService $gl) {}

    public function authorityMatrix(int $companyId, User $user): array
    {
        $company = $this->activeCompany($companyId);
        $this->assertAccess($user, $companyId);
        $mapped = AccountMapping::withoutGlobalScopes()
            ->where('company_id', $companyId)->pluck('event')->flip();

        $row = function (
            string $operationalEvent,
            ?string $accountingEvent,
            string $authority,
            string $journalDefined,
            string $periodRule,
            string $reversalDefined,
            string $status,
            string $implementation,
        ) use ($mapped): array {
            return [
                'operational_event' => $operationalEvent,
                'accounting_event' => $accountingEvent,
                'existing_authority' => $authority,
                'journal_defined' => $journalDefined,
                'mapping_configured' => $accountingEvent !== null && $mapped->has($accountingEvent),
                'period_rule' => $periodRule,
                'reversal_defined' => $reversalDefined,
                'status' => $status,
                'implementation' => $implementation,
            ];
        };

        return [
            'company' => ['id' => (int) $company->id, 'code' => $company->code, 'base_currency' => $company->base_currency],
            'rows' => [
                $row('GR POSTED / ITS PURCHASE_RECEIPT', 'GR_RECEIPT', 'PF-03 + PF-10 + BR-005/101', 'DEFINED through configured account mapping', 'goods_receipts.received_date; matching OPEN GL period', 'Existing JournalService reversal', 'PARTIAL', 'POSTABLE only for fully valued COMPANY ledger rows in company base currency'),
                $row('MATERIAL_ISSUE', 'MATERIAL_ISSUE', 'Event name exists; WIP debit boundary is not defined', 'BLOCKED', 'NOT DEFINED', 'NOT DEFINED for operational source', 'BLOCKED', 'No journal created'),
                $row('PRODUCTION_RETURN', null, 'ITS quantity/return source exists; accounting event is absent', 'NOT DEFINED', 'NOT DEFINED', 'NOT DEFINED', 'NOT DEFINED', 'No journal created'),
                $row('SUBCON_OUT / SUBCON_IN', null, 'BR-090 says valuation does not change; accounting transfer treatment is not defined', 'NOT DEFINED', 'NOT DEFINED', 'NOT DEFINED', 'NOT DEFINED', 'No journal created'),
                $row('SUBCON_FEE', 'SUBCON_FEE', 'BR-091 requires Actual Cost MO + AP; invoice/JW matching is incomplete', 'BLOCKED', 'NOT DEFINED before AP invoice authority', 'NOT DEFINED', 'BLOCKED', 'No direct fee journal created'),
                $row('PRODUCTION_RECEIPT', 'PRODUCTION_RECEIPT', 'PF-09 movement exists; FG valuation authority is not defined', 'BLOCKED', 'NOT DEFINED', 'NOT DEFINED', 'BLOCKED', 'No journal created'),
                $row('SHIPMENT / FG OUT', 'SHIPMENT_COGS', 'BR-083/PF-10 define the boundary; COGS amount authority is not defined', 'BLOCKED', 'Shipment date exists; valuation/posting basis incomplete', 'NOT DEFINED for operational shipment', 'BLOCKED', 'No COGS journal created'),
                $row('AR INVOICE / TAX / PAYMENT / FX', 'AR_INVOICE', 'Existing ArApService + Tax/FX services', 'DEFINED for configured event mappings', 'Invoice/payment source date; OPEN GL period', 'Existing JournalService reversal', 'DEFINED', 'Existing integration retained'),
                $row('AP TAX / PAYMENT / FX', 'AP_PAYMENT', 'Existing ArApService + Tax/FX services', 'DEFINED for configured event mappings', 'Invoice/payment source date; OPEN GL period', 'Existing JournalService reversal', 'DEFINED', 'Existing integration retained'),
            ],
            'late_transaction_treatment' => 'NOT DEFINED — never silently moved to another period',
            'approval' => 'AUTO journals are POSTED by existing JournalService; no new approval lifecycle introduced',
        ];
    }

    public function postGoodsReceipt(GoodsReceipt $receipt, User $user): array
    {
        return DB::transaction(function () use ($receipt, $user): array {
            $locked = GoodsReceipt::withoutGlobalScopes()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $company = $this->activeCompany((int) $locked->company_id);
            $this->assertAccess($user, (int) $locked->company_id);
            if ($locked->status !== 'POSTED') {
                throw new RuntimeException('GR harus POSTED sebelum accounting event GR_RECEIPT.');
            }

            $po = DB::table('purchase_orders as po')
                ->leftJoin('currencies as currency', function ($join): void {
                    $join->on('currency.id', '=', 'po.currency_id')->on('currency.company_id', '=', 'po.company_id');
                })
                ->where('po.company_id', $locked->company_id)->where('po.id', $locked->purchase_order_id)
                ->select(['po.id', 'po.doc_no', 'po.currency_id', 'po.exchange_rate', 'currency.code as currency_code'])
                ->lockForUpdate()->first();
            if ($po === null) throw new RuntimeException('Purchase Order sumber GR tidak tersedia pada company yang sama.');
            if ($po->currency_code === null || strtoupper($po->currency_code) !== strtoupper($company->base_currency)) {
                throw new RuntimeException('FX ACCOUNTING = NOT DEFINED — GR_RECEIPT hanya dapat diposting ketika source currency sama dengan company base currency.');
            }

            $movements = DB::table('stock_movements')
                ->where('company_id', $locked->company_id)->where('movement_type', 'PURCHASE_RECEIPT')
                ->where('source_document_type', 'goods_receipts')->where('source_document_id', $locked->id)
                ->lockForUpdate()->get();
            if ($movements->count() !== 1) {
                throw new RuntimeException('GR_RECEIPT memerlukan tepat satu ITS PURCHASE_RECEIPT yang traceable.');
            }

            $ledger = DB::table('stock_ledger')
                ->where('company_id', $locked->company_id)->where('movement_type', 'PURCHASE_RECEIPT')
                ->where('source_document_type', 'goods_receipts')->where('source_document_id', $locked->id)
                ->orderBy('id')->lockForUpdate()->get([
                    'id', 'material_id', 'warehouse_id', 'location_id', 'lot_no', 'roll_id',
                    'ownership', 'qty_in', 'uom_id', 'unit_cost', 'total_cost', 'created_at',
                ]);
            if ($ledger->isEmpty()) throw new RuntimeException('ITS PURCHASE_RECEIPT tidak memiliki ledger line.');
            if ($ledger->contains(fn ($line) => $line->ownership !== 'COMPANY')) {
                throw new RuntimeException('BR-001: buyer-owned receipt tidak boleh masuk inventory valuation journal.');
            }
            if ($ledger->contains(fn ($line) => $line->unit_cost === null || $line->total_cost === null)) {
                throw new RuntimeException('GR_RECEIPT tidak dapat diposting karena source ITS belum memiliki valuation lengkap.');
            }

            $amount = round((float) $ledger->sum('total_cost'), 4);
            if ($amount <= 0) throw new RuntimeException('GR_RECEIPT memerlukan valued amount > 0.');
            $journalDate = $locked->received_date->toDateString();
            $period = $locked->received_date->format('Y-m');
            $posted = $this->gl->postEvent(
                (int) $locked->company_id,
                'GR_RECEIPT',
                'goods_receipts',
                (int) $locked->id,
                $amount,
                $period,
                $user,
                "GR receipt {$locked->doc_no} / PO {$po->doc_no}",
                $journalDate,
            );

            return [
                'created' => $posted['created'],
                'accounting_event' => 'GR_RECEIPT',
                'amount' => $amount,
                'currency' => $company->base_currency,
                'posting_date' => $journalDate,
                'period' => $period,
                'source' => [
                    'module' => 'Receiving', 'document_type' => 'goods_receipts',
                    'id' => (int) $locked->id, 'doc_no' => $locked->doc_no,
                    'purchase_order_id' => (int) $po->id, 'purchase_order_no' => $po->doc_no,
                    'stock_movement_id' => (int) $movements->first()->id,
                    'stock_movement_no' => $movements->first()->doc_no,
                    'ledger_ids' => $ledger->pluck('id')->map(fn ($id) => (int) $id)->all(),
                ],
                'journal' => $posted['journal']->fresh('lines'),
            ];
        });
    }

    public function journalLineage(Journal $journal, User $user): array
    {
        $loaded = Journal::withoutGlobalScopes()->with('lines.account')->whereKey($journal->id)->firstOrFail();
        $this->activeCompany((int) $loaded->company_id);
        $this->assertAccess($user, (int) $loaded->company_id);
        $period = DB::table('gl_periods')->where('company_id', $loaded->company_id)->where('period', $loaded->period)->first();
        $reversal = Journal::withoutGlobalScopes()->where('company_id', $loaded->company_id)
            ->where('reverses_journal_id', $loaded->id)->first();
        $original = $loaded->reverses_journal_id
            ? Journal::withoutGlobalScopes()->where('company_id', $loaded->company_id)->whereKey($loaded->reverses_journal_id)->first()
            : null;

        return [
            'journal' => [
                'id' => $loaded->id, 'doc_no' => $loaded->doc_no, 'source' => $loaded->source,
                'event' => $loaded->event, 'status' => $loaded->status,
                'period' => $loaded->period, 'journal_date' => $loaded->journal_date->toDateString(),
                'amount' => (float) $loaded->total_debit, 'created_by' => $loaded->created_by,
                'posting_key' => $loaded->posting_key,
            ],
            'lines' => $loaded->lines->map(fn ($line) => [
                'id' => $line->id, 'coa_id' => $line->coa_id,
                'account_code' => $line->account?->code, 'account_name' => $line->account?->name,
                'debit' => (float) $line->debit, 'credit' => (float) $line->credit, 'memo' => $line->memo,
            ])->values(),
            'gl_period' => ['period' => $loaded->period, 'status' => $period?->status ?? 'NOT_CONFIGURED'],
            'operational_source' => $this->sourceDetails($loaded),
            'reversal' => $reversal ? ['id' => $reversal->id, 'doc_no' => $reversal->doc_no, 'period' => $reversal->period] : ['status' => 'NOT_REVERSED'],
            'reverses_original' => $original ? ['id' => $original->id, 'doc_no' => $original->doc_no] : null,
            'lineage' => 'operational source → accounting event → journal/header lines → GL period',
        ];
    }

    private function sourceDetails(Journal $journal): array
    {
        if ($journal->source_document_type === 'goods_receipts') {
            $source = DB::table('goods_receipts')->where('company_id', $journal->company_id)
                ->where('id', $journal->source_document_id)->first();
            $movement = DB::table('stock_movements')->where('company_id', $journal->company_id)
                ->where('movement_type', 'PURCHASE_RECEIPT')->where('source_document_type', 'goods_receipts')
                ->where('source_document_id', $journal->source_document_id)->first();
            return [
                'available' => $source !== null,
                'module' => 'Receiving', 'document_type' => 'goods_receipts',
                'id' => $journal->source_document_id, 'doc_no' => $source?->doc_no,
                'source_date' => $source?->received_date,
                'its_movement' => $movement ? ['id' => $movement->id, 'doc_no' => $movement->doc_no, 'movement_type' => $movement->movement_type] : null,
            ];
        }
        if ($journal->source_document_type === 'journals') {
            $source = Journal::withoutGlobalScopes()->where('company_id', $journal->company_id)
                ->whereKey($journal->source_document_id)->first();
            return ['available' => $source !== null, 'module' => 'Finance', 'document_type' => 'journals', 'id' => $journal->source_document_id, 'doc_no' => $source?->doc_no];
        }

        $safeSources = [
            'ar_invoices' => ['Finance/AR', 'invoice_date'],
            'ar_payments' => ['Finance/AR', 'payment_date'],
            'supplier_invoices' => ['Finance/AP', 'invoice_date'],
            'ap_payments' => ['Finance/AP', 'payment_date'],
            'bank_statement_lines' => ['Finance/Bank', 'transaction_date'],
        ];
        $adapter = $safeSources[$journal->source_document_type] ?? null;
        if ($adapter === null) {
            return ['available' => false, 'document_type' => $journal->source_document_type, 'id' => $journal->source_document_id, 'authority' => 'UNAVAILABLE_NO_SAFE_SOURCE_ADAPTER'];
        }
        $source = DB::table($journal->source_document_type)->where('company_id', $journal->company_id)
            ->where('id', $journal->source_document_id)->first();
        return [
            'available' => $source !== null, 'module' => $adapter[0],
            'document_type' => $journal->source_document_type, 'id' => $journal->source_document_id,
            'doc_no' => $source?->doc_no, 'source_date' => $source?->{$adapter[1]},
        ];
    }

    private function activeCompany(int $companyId): object
    {
        $company = DB::table('companies')->where('id', $companyId)->whereNull('deleted_at')->first();
        if ($company === null || ! (bool) $company->is_active) throw new RuntimeException('Company Finance tidak aktif.');
        return $company;
    }

    private function assertAccess(User $user, int $companyId): void
    {
        if ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists()) {
            throw new RuntimeException('User tidak memiliki akses ke company Finance.');
        }
    }
}
