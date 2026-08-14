<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\ArPayment;
use Modules\Finance\Models\ApPayment;
use Modules\Purchasing\Models\SupplierInvoice;
use Modules\Shipping\Models\Shipment;
use RuntimeException;

/**
 * AR: invoice penjualan dari shipment (harga SO, kurs tersimpan BR-102) + pembayaran customer.
 * AP: pembayaran supplier — HANYA untuk invoice MATCHED (BR-050).
 * Aging: bucket 0–30 / 31–60 / 61–90 / >90 hari dari due_date.
 */
class ArApService
{
    public function __construct(
        private NumberingService $numbering,
        private GlPostingService $gl,
        private AuditService $audit,
    ) {}

    /** Buat AR invoice dari shipment SHIPPED — lines dari shipment lines × harga SO. */
    public function createArInvoiceFromShipment(Shipment $shipment, User $user, ?string $dueDate = null): ArInvoice
    {
        if ($shipment->status !== 'SHIPPED') {
            throw new RuntimeException('AR invoice hanya dari shipment berstatus SHIPPED.');
        }

        // Idempotent: satu invoice per shipment
        $existing = ArInvoice::where('shipment_id', $shipment->id)->where('status', '!=', 'VOID')->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($shipment, $user, $dueDate): ArInvoice {
            $so = $shipment->salesOrder->load('lines');
            $companyId = $shipment->company_id;
            $total = 0.0;
            $linesPayload = [];

            foreach ($shipment->lines as $line) {
                $soLine = $so->lines
                    ->where('style_id', $line->style_id)
                    ->where('colorway_id', $line->colorway_id)
                    ->where('size_id', $line->size_id)
                    ->first();

                $price = (float) ($soLine?->price ?? 0);
                $amount = round((float) $line->qty_shipped * $price, 4);
                $total += $amount;

                $linesPayload[] = [
                    'style_id' => $line->style_id,
                    'description' => "Shipment {$shipment->doc_no} — style #{$line->style_id}",
                    'qty' => (float) $line->qty_shipped,
                    'unit_price' => $price,
                    'amount' => $amount,
                ];
            }

            $invoice = ArInvoice::create([
                'company_id' => $companyId,
                'doc_no' => $this->numbering->next($companyId, 'INV'),
                'customer_id' => $so->customer_id,
                'sales_order_id' => $so->id,
                'shipment_id' => $shipment->id,
                'invoice_date' => now()->toDateString(),
                'due_date' => $dueDate,
                'currency_id' => $so->currency_id,
                'exchange_rate' => $so->exchange_rate ?? 1,   // BR-102
                'total_amount' => round($total, 4),
                'status' => 'OPEN',
                'created_by' => $user->id,
            ]);

            foreach ($linesPayload as $payload) {
                $invoice->lines()->create($payload);
            }

            // Jurnal AUTO: Piutang ↑ / Pendapatan ↑ (dalam base currency — BR-102)
            $baseAmount = round($total * (float) $invoice->exchange_rate, 4);
            $this->gl->postEvent(
                $companyId, 'AR_INVOICE', 'ar_invoices', $invoice->id,
                $baseAmount, now()->format('Y-m'), $user,
                "AR Invoice {$invoice->doc_no} — {$shipment->doc_no}",
            );

            $this->audit->record('create', $invoice, after: ['doc_no' => $invoice->doc_no, 'total' => $total]);

            return $invoice->load('lines');
        });
    }

    /** Pembayaran customer — parsial didukung; status OPEN → PARTIAL → PAID. */
    public function recordArPayment(ArInvoice $invoice, array $data, User $user): ArPayment
    {
        if (in_array($invoice->status, ['PAID', 'VOID'], true)) {
            throw new RuntimeException("Invoice {$invoice->doc_no} berstatus {$invoice->status}.");
        }

        $amount = (float) $data['amount'];
        if ($amount <= 0 || $amount > $invoice->outstanding() + 0.0001) {
            throw new RuntimeException("Amount {$amount} tidak valid (outstanding: {$invoice->outstanding()}).");
        }

        return DB::transaction(function () use ($invoice, $data, $amount, $user): ArPayment {
            $payment = ArPayment::create([
                'company_id' => $invoice->company_id,
                'doc_no' => $this->numbering->next($invoice->company_id, 'PAY'),
                'ar_invoice_id' => $invoice->id,
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'amount' => $amount,
                'method' => $data['method'] ?? null,
                'reference_no' => $data['reference_no'] ?? null,
                'created_by' => $user->id,
            ]);

            $invoice->increment('paid_amount', $amount);
            $fresh = $invoice->fresh();
            $fresh->update(['status' => $fresh->outstanding() <= 0.0001 ? 'PAID' : 'PARTIAL']);

            $baseAmount = round($amount * (float) $invoice->exchange_rate, 4);
            $this->gl->postEvent(
                $invoice->company_id, 'AR_PAYMENT', 'ar_payments', $payment->id,
                $baseAmount, now()->format('Y-m'), $user,
                "Payment {$payment->doc_no} untuk {$invoice->doc_no}",
            );

            $this->audit->record('create', $payment, after: ['doc_no' => $payment->doc_no, 'amount' => $amount]);

            return $payment;
        });
    }

    /** BR-050: AP payment hanya untuk supplier invoice MATCHED. */
    public function recordApPayment(SupplierInvoice $invoice, array $data, User $user): ApPayment
    {
        if ($invoice->match_status !== 'MATCHED') {
            throw new RuntimeException("BR-050: invoice {$invoice->doc_no} belum MATCHED (3-way match) — tidak bisa dibayar.");
        }
        if ($invoice->status === 'PAID') {
            throw new RuntimeException("Invoice {$invoice->doc_no} sudah PAID.");
        }

        $amount = (float) $data['amount'];
        $paid = (float) ApPayment::where('supplier_invoice_id', $invoice->id)->sum('amount');
        $outstanding = (float) $invoice->total_amount - $paid;

        if ($amount <= 0 || $amount > $outstanding + 0.0001) {
            throw new RuntimeException("Amount {$amount} tidak valid (outstanding: {$outstanding}).");
        }

        return DB::transaction(function () use ($invoice, $data, $amount, $outstanding, $user): ApPayment {
            $payment = ApPayment::create([
                'company_id' => $invoice->company_id,
                'doc_no' => $this->numbering->next($invoice->company_id, 'PAY'),
                'supplier_invoice_id' => $invoice->id,
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'amount' => $amount,
                'method' => $data['method'] ?? null,
                'reference_no' => $data['reference_no'] ?? null,
                'created_by' => $user->id,
            ]);

            if ($amount >= $outstanding - 0.0001) {
                $invoice->update(['status' => 'PAID']);
            }

            $this->gl->postEvent(
                $invoice->company_id, 'AP_PAYMENT', 'ap_payments', $payment->id,
                $amount, now()->format('Y-m'), $user,
                "AP Payment {$payment->doc_no} untuk {$invoice->doc_no}",
            );

            $this->audit->record('create', $payment, after: ['doc_no' => $payment->doc_no, 'amount' => $amount]);

            return $payment;
        });
    }

    /** Aging AR per customer (bucket hari lewat due_date per tanggal $asOf). */
    public function agingAr(int $companyId, string $asOf): array
    {
        $invoices = ArInvoice::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('status', ['OPEN', 'PARTIAL'])
            ->get();

        return $this->bucketize($invoices->map(fn ($i) => [
            'party' => $i->customer->name ?? (string) $i->customer_id,
            'doc_no' => $i->doc_no,
            'due_date' => $i->due_date ?? $i->invoice_date,
            'outstanding' => $i->outstanding(),
        ])->all(), $asOf);
    }

    /** Aging AP per supplier. */
    public function agingAp(int $companyId, string $asOf): array
    {
        $invoices = SupplierInvoice::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED'])
            ->where('match_status', 'MATCHED')
            ->get();

        return $this->bucketize($invoices->map(function ($i) {
            $paid = (float) ApPayment::where('supplier_invoice_id', $i->id)->sum('amount');
            return [
                'party' => $i->supplier->name ?? (string) $i->supplier_id,
                'doc_no' => $i->doc_no,
                'due_date' => $i->due_date ?? $i->invoice_date,
                'outstanding' => (float) $i->total_amount - $paid,
            ];
        })->all(), $asOf);
    }

    private function bucketize(array $items, string $asOf): array
    {
        $asOfDate = \Carbon\Carbon::parse($asOf);
        $buckets = ['current' => [], '1_30' => [], '31_60' => [], '61_90' => [], 'over_90' => []];

        foreach ($items as $item) {
            if ($item['outstanding'] <= 0) {
                continue;
            }
            $daysPastDue = $asOfDate->diffInDays(\Carbon\Carbon::parse($item['due_date']), false) * -1;
            $key = match (true) {
                $daysPastDue <= 0 => 'current',
                $daysPastDue <= 30 => '1_30',
                $daysPastDue <= 60 => '31_60',
                $daysPastDue <= 90 => '61_90',
                default => 'over_90',
            };
            $buckets[$key][] = $item + ['days_past_due' => max(0, $daysPastDue)];
        }

        return $buckets;
    }
}
