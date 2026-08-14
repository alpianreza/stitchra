<?php

use Modules\Core\Models\User;
use Modules\Finance\Models\AccountMapping;
use Modules\Finance\Models\Journal;
use Modules\Finance\Services\ArApService;
use Modules\Finance\Services\GlPostingService;
use Modules\MasterData\Models\ChartOfAccount;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Supplier;
use Modules\MasterData\Models\Uom;
use Modules\MasterData\Models\Warehouse;
use Modules\Packing\Services\PackingService;
use Modules\Purchasing\Models\SupplierInvoice;
use Modules\Purchasing\Services\PurchasingService;
use Modules\Qc\Services\QcService;
use Modules\Receiving\Services\ReceivingService;
use Modules\Shipping\Services\ShipmentService;

/** Mapping akun standar untuk test */
function glMappings(): array
{
    $persediaan = coa('1130', 'ASSET', 'DEBIT');     // Persediaan RM
    $utang = coa('2101', 'LIABILITY', 'CREDIT');     // Utang AP
    $piutang = coa('1120', 'ASSET', 'DEBIT');        // Piutang AR
    $pendapatan = coa('4101', 'REVENUE', 'CREDIT');  // Pendapatan
    $kas = coa('1101', 'ASSET', 'DEBIT');            // Kas/Bank

    $map = [
        'GR_RECEIPT' => [$persediaan->id, $utang->id],
        'AR_INVOICE' => [$piutang->id, $pendapatan->id],
        'AR_PAYMENT' => [$kas->id, $piutang->id],
        'AP_PAYMENT' => [$utang->id, $kas->id],
    ];

    foreach ($map as $event => [$debit, $credit]) {
        AccountMapping::create(['company_id' => 1, 'event' => $event, 'debit_account_id' => $debit, 'credit_account_id' => $credit]);
    }

    return compact('persediaan', 'utang', 'piutang', 'pendapatan', 'kas');
}

test('BR-101: jurnal AUTO gagal jelas bila mapping belum ada (tidak mengarang akun)', function () {
    $user = User::factory()->create(['company_id' => 1]);

    app(GlPostingService::class)->postEvent(1, 'GR_RECEIPT', 'goods_receipts', 1, 1000, '2026-08', $user);
})->throws(RuntimeException::class);

test('jurnal AUTO deterministik + idempotent (event+dokumen sama tidak ganda)', function () {
    $user = User::factory()->create(['company_id' => 1]);
    glMappings();

    $gl = app(GlPostingService::class);

    $first = $gl->postEvent(1, 'GR_RECEIPT', 'goods_receipts', 42, 2500000, '2026-08', $user, 'GR test');
    expect($first['created'])->toBeTrue();
    expect($first['journal']->source)->toBe('AUTO');
    expect((float) $first['journal']->total_debit)->toBe(2500000.0);

    // Panggil lagi — tidak membuat jurnal baru
    $second = $gl->postEvent(1, 'GR_RECEIPT', 'goods_receipts', 42, 2500000, '2026-08', $user);
    expect($second['created'])->toBeFalse();
    expect($second['journal']->id)->toBe($first['journal']->id);
    expect(Journal::withoutGlobalScopes()->count())->toBe(1);
});

test('BR-050: AP payment hanya untuk invoice MATCHED; lunas → PAID + jurnal', function () {
    $user = User::factory()->create(['company_id' => 1]);
    glMappings();

    $supplier = Supplier::create(['company_id' => 1, 'code' => 'SUP-'.uniqid(), 'name' => 'Tex', 'type' => 'FABRIC']);
    $invoice = SupplierInvoice::create([
        'company_id' => 1, 'doc_no' => 'INV-S-'.uniqid(), 'supplier_id' => $supplier->id,
        'invoice_date' => now()->toDateString(), 'total_amount' => 1000, 'created_by' => $user->id,
    ]);

    $arAp = app(ArApService::class);

    // Belum MATCHED → tolak
    try {
        $arAp->recordApPayment($invoice, ['amount' => 500], $user);
        $this->fail('Invoice belum MATCHED harus ditolak');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('BR-050');
    }

    $invoice->update(['match_status' => 'MATCHED', 'status' => 'APPROVED']);

    // Bayar parsial 600 → masih APPROVED
    $arAp->recordApPayment($invoice->fresh(), ['amount' => 600], $user);
    expect($invoice->fresh()->status)->toBe('APPROVED');

    // Lunasi 400 → PAID + jurnal AP_PAYMENT
    $arAp->recordApPayment($invoice->fresh(), ['amount' => 400], $user);
    expect($invoice->fresh()->status)->toBe('PAID');

    $journal = Journal::withoutGlobalScopes()->where('event', 'AP_PAYMENT')->first();
    expect($journal)->not->toBeNull();
    expect((float) $journal->total_debit)->toBe(600.0);   // jurnal pembayaran pertama

    // Overpayment → ditolak
    $arAp->recordApPayment($invoice->fresh(), ['amount' => 1], $user);
})->throws(RuntimeException::class);

test('AR dari shipment SHIPPED: invoice + jurnal AR_INVOICE; pembayaran parsial → PARTIAL → PAID; aging bucket benar', function () {
    [$user, $customer, $style, $so, $mo, $colorway, $size, $fgWh] = packFixture(soQty: 100, tolerance: 5);
    glMappings();

    // QC pass → packing → finalize → ship (FG keluar)
    $qc = app(QcService::class);
    $qc->finalize($qc->create($mo, 'FINAL', 100, $user), $user);
    $packing = app(PackingService::class);
    $pl = $packing->create($so, $mo->id, $user);
    $packing->addCarton($pl->fresh(), [], [['style_id' => $style->id, 'colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty' => 100]], $user);
    $pl = $packing->finalize($pl->fresh(), $fgWh->id, $user);
    $shipSvc = app(ShipmentService::class);
    $shipment = $shipSvc->create($pl, ['ship_date' => now()->toDateString()], $user);
    $shipSvc->ship($shipment, $fgWh->id, $user);

    // AR invoice: 100 × 15 = 1500
    $arAp = app(ArApService::class);
    $invoice = $arAp->createArInvoiceFromShipment($shipment->fresh(), $user, now()->addDays(30)->toDateString());

    expect((float) $invoice->total_amount)->toBe(1500.0);
    expect($invoice->status)->toBe('OPEN');

    // Jurnal AR_INVOICE terposting otomatis (BR-101/102)
    $journal = Journal::withoutGlobalScopes()->where('event', 'AR_INVOICE')->where('source_document_id', $invoice->id)->first();
    expect($journal)->not->toBeNull();
    expect((float) $journal->total_debit)->toBe(1500.0);

    // Idempotent: shipment sama → invoice yang sama
    expect($arAp->createArInvoiceFromShipment($shipment->fresh(), $user)->id)->toBe($invoice->id);

    // Pembayaran parsial → PARTIAL
    $arAp->recordArPayment($invoice->fresh(), ['amount' => 500], $user);
    expect($invoice->fresh()->status)->toBe('PARTIAL');
    expect((float) $invoice->fresh()->paid_amount)->toBe(500.0);

    // Lunasi → PAID
    $arAp->recordArPayment($invoice->fresh(), ['amount' => 1000], $user);
    expect($invoice->fresh()->status)->toBe('PAID');

    // Overpayment → ditolak
    $arAp->recordArPayment($invoice->fresh(), ['amount' => 1], $user);
})->throws(RuntimeException::class);

test('aging AR: invoice lewat due 45 hari → bucket 31_60', function () {
    $user = User::factory()->create(['company_id' => 1]);
    glMappings();
    $customer = \Modules\MasterData\Models\Customer::create(['company_id' => 1, 'code' => 'C-'.uniqid(), 'name' => 'Buyer Lama']);
    $style = \Modules\MasterData\Models\Style::create(['company_id' => 1, 'style_no' => 'ST-'.uniqid(), 'category' => 'WOVEN']);
    $soModel = \Modules\Sales\Models\SalesOrder::create([
        'company_id' => 1, 'doc_no' => 'SO-TEST-'.uniqid(), 'customer_id' => $customer->id,
        'order_date' => now()->toDateString(), 'status' => 'CLOSED', 'created_by' => $user->id,
    ]);

    \Modules\Finance\Models\ArInvoice::create([
        'company_id' => 1, 'doc_no' => 'INV-A-'.uniqid(), 'customer_id' => $customer->id,
        'sales_order_id' => $soModel->id, 'invoice_date' => now()->subDays(60)->toDateString(),
        'due_date' => now()->subDays(45)->toDateString(),   // 45 hari lewat jatuh tempo
        'total_amount' => 750, 'status' => 'OPEN', 'created_by' => $user->id,
    ]);

    $aging = app(ArApService::class)->agingAr(1, now()->toDateString());

    expect($aging['31_60'])->toHaveCount(1);
    expect((float) $aging['31_60'][0]['outstanding'])->toBe(750.0);
    expect($aging['current'])->toHaveCount(0);
});
