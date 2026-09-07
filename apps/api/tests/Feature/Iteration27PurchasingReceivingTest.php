<?php

use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\Company;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Support\CurrentCompany;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockLedger;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Currency;
use Modules\MasterData\Models\Location;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Supplier;
use Modules\MasterData\Models\Uom;
use Modules\MasterData\Models\Warehouse;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\Rfq;
use Modules\Purchasing\Services\PurchasingService;
use Modules\Purchasing\Services\SourcingService;
use Modules\Receiving\Models\Putaway;
use Modules\Receiving\Models\SupplierReturn;
use Modules\Receiving\Services\InwardQcService;
use Modules\Receiving\Services\PutawayService;
use Modules\Receiving\Services\ReceiptStockService;
use Modules\Receiving\Services\ReceivingService;
use Modules\Receiving\Services\ReceivingTraceService;
use Modules\Receiving\Services\SupplierReturnService;

beforeEach(fn () => CurrentCompany::clear());
afterEach(fn () => CurrentCompany::clear());

function i27Fixture(bool $roll = false): array
{
    Company::withoutGlobalScopes()->findOrFail(1)->update(['base_currency' => 'USD']);
    $user = User::factory()->create(['company_id' => 1]);
    $uom = Uom::firstOrCreate(['company_id' => 1, 'code' => $roll ? 'MTR' : 'PCS'], ['name' => $roll ? 'Meter' : 'Piece']);
    $material = Material::create(['company_id' => 1, 'code' => 'I27-'.uniqid(), 'name' => 'Iteration 27 Material',
        'type' => $roll ? 'FABRIC' : 'TRIM', 'tracking_level' => $roll ? 'ROLL' : 'LOT', 'buy_uom_id' => $uom->id, 'use_uom_id' => $uom->id]);
    $supplier = Supplier::create(['company_id' => 1, 'code' => 'I27-S1-'.uniqid(), 'name' => 'Supplier A', 'type' => 'TRIM']);
    $otherSupplier = Supplier::create(['company_id' => 1, 'code' => 'I27-S2-'.uniqid(), 'name' => 'Supplier B', 'type' => 'TRIM']);
    $currency = Currency::firstOrCreate(['company_id' => 1, 'code' => 'USD'], ['name' => 'US Dollar']);
    $warehouse = Warehouse::create(['company_id' => 1, 'code' => 'I27-W-'.uniqid(), 'name' => 'Receiving', 'type' => 'RM']);
    $staging = Location::create(['warehouse_id' => $warehouse->id, 'code' => 'DOCK']);
    $rack = Location::create(['warehouse_id' => $warehouse->id, 'code' => 'RACK-A']);

    return compact('user', 'uom', 'material', 'supplier', 'otherSupplier', 'currency', 'warehouse', 'staging', 'rack');
}

function i27Rfq(array $f): Rfq
{
    return app(SourcingService::class)->createRfq(1, ['deadline' => '2026-09-10'], [[
        'material_id' => $f['material']->id, 'qty' => 10, 'uom_id' => $f['uom']->id,
    ]], [$f['supplier']->id, $f['otherSupplier']->id], $f['user']);
}

function i27QuoteData(array $f, Rfq $rfq): array
{
    return ['supplier_id' => $f['supplier']->id, 'currency_id' => $f['currency']->id,
        'exchange_rate' => 1, 'quotation_no' => 'Q-001', 'quoted_date' => '2026-09-07',
        'valid_until' => '2026-09-30', 'payment_term' => 'Net 30', 'lead_time_days' => 3,
        'lines' => $rfq->lines->map(fn ($line) => ['rfq_line_id' => $line->id, 'unit_price' => 5.125])->all()];
}

function i27AwardData(): array
{
    return ['order_date' => '2026-09-07', 'reason' => 'Lead time dan mutu dipilih purchasing.'];
}

function i27Receipt(array $f, bool $roll = false): array
{
    $po = app(PurchasingService::class)->createPo(1, ['supplier_id' => $f['supplier']->id, 'order_date' => '2026-09-07'], [[
        'material_id' => $f['material']->id, 'qty' => 10, 'uom_id' => $f['uom']->id, 'unit_price' => 5,
    ]], $f['user']);
    $po->update(['status' => 'APPROVED']);
    $input = ['po_line_id' => $po->lines->first()->id, 'qty_received' => 10, 'location_id' => $f['staging']->id, 'lot_no' => 'LOT-I27'];
    if ($roll) {
        $input['rolls'] = [
            ['roll_no' => 'I27-R1-'.uniqid(), 'qty_buy' => 5, 'qty_use_actual' => 5, 'lot_no' => 'LOT-I27'],
            ['roll_no' => 'I27-R2-'.uniqid(), 'qty_buy' => 5, 'qty_use_actual' => 5, 'lot_no' => 'LOT-I27'],
        ];
    }
    $gr = app(ReceivingService::class)->createAndPost(1, ['purchase_order_id' => $po->id, 'warehouse_id' => $f['warehouse']->id, 'received_date' => '2026-09-07'], [$input], $f['user']);
    $line = $gr->lines->first();

    return [$gr, $line, $po];
}

function i27Qc(array $f, $gr, string $result = 'PASS', ?array $units = null)
{
    $units ??= $gr->lines->flatMap(fn ($line) => $line->rolls->isEmpty()
        ? [['gr_line_id' => $line->id, 'result' => $result]]
        : $line->rolls->map(fn ($roll) => ['gr_line_id' => $line->id, 'roll_id' => $roll->id, 'result' => $result])->all())->all();
    $qc = app(InwardQcService::class);
    $inspection = $qc->create(1, $gr, $units, $f['user']);
    $qc->finalize($inspection, [], $f['user']);

    return $inspection->fresh();
}

function i27Putaway(array $f, $gr, $line, float $qty = 10): Putaway
{
    return app(PutawayService::class)->create(1, $gr, [['gr_line_id' => $line->id, 'to_location_id' => $f['rack']->id, 'qty' => $qty]], null, $f['user']);
}

function i27Balance(array $f, $location): StockBalance
{
    return StockBalance::withoutGlobalScopes()->where('material_id', $f['material']->id)->where('location_id', $location->id)->firstOrFail();
}

test('I27 RFQ quotation comparison manual award preserves snapshots and leaves PO draft', function () {
    $f = i27Fixture();
    $service = app(SourcingService::class);
    $rfq = i27Rfq($f);
    $quote = $service->addQuotation($rfq, i27QuoteData($f, $rfq), $f['user']);
    $comparison = $service->comparison($rfq, $f['user']);
    expect($comparison->quotations->first()->total_amount)->toBe(51.25)->and($comparison->quotations->first()->base_total)->toBe(51.25);
    $po = $service->award($rfq, $quote->id, i27AwardData(), $f['user']);
    $again = $service->award($rfq, $quote->id, i27AwardData(), $f['user']);
    expect($again->id)->toBe($po->id)->and(PurchaseOrder::count())->toBe(1)
        ->and($po->status)->toBe('DRAFT')->and($po->total_amount)->toBe('51.2500')
        ->and($po->currency_id)->toBe($f['currency']->id)->and($po->exchange_rate)->toBe('1.000000000000')
        ->and($po->expected_date->toDateString())->toBe('2026-09-10')->and($po->payment_term)->toBe('Net 30')
        ->and($po->lines->first()->quotation_line_id)->toBe($quote->lines->first()->id)
        ->and($rfq->fresh()->status)->toBe('AWARDED')->and($quote->fresh()->is_selected)->toBeTrue();
    expect(fn () => app(PurchasingService::class)->submitPo($po, $f['user']))->toThrow(RuntimeException::class, 'Approval flow');
    expect($po->fresh()->status)->toBe('DRAFT');
});

test('I27 changing award choice or payload never creates a second PO', function () {
    $f = i27Fixture();
    $s = app(SourcingService::class);
    $rfq = i27Rfq($f);
    $data = i27QuoteData($f, $rfq);
    $a = $s->addQuotation($rfq, $data, $f['user']);
    $b = $s->addQuotation($rfq, array_replace($data, ['supplier_id' => $f['otherSupplier']->id]), $f['user']);
    $s->award($rfq, $a->id, i27AwardData(), $f['user']);
    expect(fn () => $s->award($rfq, $b->id, i27AwardData(), $f['user']))->toThrow(RuntimeException::class)
        ->and(fn () => $s->award($rfq, $a->id, array_replace(i27AwardData(), ['order_date' => '2026-09-08']), $f['user']))->toThrow(RuntimeException::class)
        ->and(PurchaseOrder::count())->toBe(1)->and($b->fresh()->is_selected)->toBeFalse();
});

test('I27 client cannot override RFQ quantities or forge PO receipt and quotation lineage', function () {
    $f = i27Fixture();
    $rfq = i27Rfq($f);
    $data = i27QuoteData($f, $rfq);
    $data['lines'][0] += ['qty' => 900, 'material_id' => 999999, 'uom_id' => 999999];
    $data['is_selected'] = true;
    $quote = app(SourcingService::class)->addQuotation($rfq, $data, $f['user']);
    expect($quote->lines->first()->qty)->toBe('10.0000')->and($quote->is_selected)->toBeFalse();
    $po = app(PurchasingService::class)->createPo(1, ['supplier_id' => $f['supplier']->id, 'order_date' => '2026-09-07'], [[
        'material_id' => $f['material']->id, 'qty' => 1, 'uom_id' => $f['uom']->id, 'unit_price' => 1,
        'received_qty' => 99, 'quotation_line_id' => $quote->lines->first()->id,
    ]], $f['user']);
    expect((float) $po->fresh('lines')->lines->first()->received_qty)->toBe(0.0)->and($po->lines->first()->quotation_line_id)->toBeNull();
});

test('I27 RFQ PR reference requires approved matching material and UOM', function () {
    $f = i27Fixture();
    $p = app(PurchasingService::class);
    $pr = $p->createPr(1, [], [['material_id' => $f['material']->id, 'qty' => 10, 'uom_id' => $f['uom']->id]], 'MANUAL', $f['user']);
    $input = [['material_id' => $f['material']->id, 'qty' => 10, 'uom_id' => $f['uom']->id, 'pr_line_id' => $pr->lines->first()->id]];
    $s = app(SourcingService::class);
    expect(fn () => $s->createRfq(1, [], $input, [$f['supplier']->id], $f['user']))->toThrow(RuntimeException::class);
    $pr->update(['status' => 'APPROVED']);
    $rfq = $s->createRfq(1, [], $input, [$f['supplier']->id], $f['user']);
    $q = $s->addQuotation($rfq, i27QuoteData($f, $rfq), $f['user']);
    expect($s->award($rfq, $q->id, i27AwardData(), $f['user'])->lines->first()->pr_line_id)->toBe($pr->lines->first()->id);
});

test('I27 quote rejects supplier outside RFQ and lines from a different RFQ', function () {
    $f = i27Fixture();
    $s = app(SourcingService::class);
    $rfq = i27Rfq($f);
    $data = i27QuoteData($f, $rfq);
    $third = Supplier::create(['company_id' => 1, 'code' => 'THIRD', 'name' => 'Not invited', 'type' => 'TRIM']);
    expect(fn () => $s->addQuotation($rfq, array_replace($data, ['supplier_id' => $third->id]), $f['user']))->toThrow(RuntimeException::class);
    $other = i27Rfq($f);
    $data['lines'][0]['rfq_line_id'] = $other->lines->first()->id;
    expect(fn () => $s->addQuotation($rfq, $data, $f['user']))->toThrow(RuntimeException::class)->and($rfq->quotations()->count())->toBe(0);
});

test('I27 base currency rate must be one and comparison uses quotation rate snapshot', function () {
    $f = i27Fixture();
    $s = app(SourcingService::class);
    $rfq = i27Rfq($f);
    $data = i27QuoteData($f, $rfq);
    expect(fn () => $s->addQuotation($rfq, array_replace($data, ['exchange_rate' => 2]), $f['user']))->toThrow(RuntimeException::class);
    $idr = Currency::create(['company_id' => 1, 'code' => 'IDR', 'name' => 'Rupiah']);
    $data['currency_id'] = $idr->id;
    $data['exchange_rate'] = 0.00005;
    $data['lines'][0]['unit_price'] = 100000;
    $quote = $s->addQuotation($rfq, $data, $f['user']);
    expect($s->comparison($rfq, $f['user'])->quotations->first()->base_total)->toBe(50.0)
        ->and($s->award($rfq, $quote->id, i27AwardData(), $f['user'])->exchange_rate)->toBe('0.000050000000');
});

test('I27 closed RFQ accepts award but no new quotations', function () {
    $f = i27Fixture();
    $s = app(SourcingService::class);
    $rfq = i27Rfq($f);
    $data = i27QuoteData($f, $rfq);
    $quote = $s->addQuotation($rfq, $data, $f['user']);
    $s->close($rfq, $f['user']);
    expect(fn () => $s->addQuotation($rfq, $data, $f['user']))->toThrow(RuntimeException::class);
    expect($s->award($rfq, $quote->id, i27AwardData(), $f['user'])->status)->toBe('DRAFT');
});

test('I27 award rejects expired or incomplete legacy quotation without creating a PO', function () {
    $f = i27Fixture();
    $s = app(SourcingService::class);
    $rfq = i27Rfq($f);
    $data = i27QuoteData($f, $rfq);
    $data['valid_until'] = '2026-09-07';
    $q = $s->addQuotation($rfq, $data, $f['user']);
    expect(fn () => $s->award($rfq, $q->id, array_replace(i27AwardData(), ['order_date' => '2026-09-08']), $f['user']))->toThrow(RuntimeException::class);
    $q->update(['exchange_rate' => null]);
    expect(fn () => $s->award($rfq, $q->id, i27AwardData(), $f['user']))->toThrow(RuntimeException::class)->and(PurchaseOrder::count())->toBe(0)->and($rfq->fresh()->status)->toBe('OPEN');
});

test('I27 sourcing rejects foreign company references and callers', function () {
    $f = i27Fixture();
    $other = Company::create(['code' => 'I27-OTHER', 'name' => 'Other', 'base_currency' => 'USD']);
    $supplier = Supplier::create(['company_id' => $other->id, 'code' => 'CROSS', 'name' => 'Foreign supplier', 'type' => 'TRIM']);
    expect(fn () => app(SourcingService::class)->createRfq(1, [], [['material_id' => $f['material']->id, 'qty' => 1, 'uom_id' => $f['uom']->id]], [$supplier->id], $f['user']))->toThrow(RuntimeException::class);
    $rfq = i27Rfq($f);
    $foreignUser = User::factory()->create(['company_id' => $other->id]);
    expect(fn () => app(SourcingService::class)->comparison($rfq, $foreignUser))->toThrow(RuntimeException::class);
});

test('I27 QC releases the original non-null location and lot exactly once', function () {
    $f = i27Fixture();
    [$gr, $line] = i27Receipt($f);
    $qc = app(InwardQcService::class);
    $pending = $qc->create(1, $gr, [['gr_line_id' => $line->id, 'result' => 'FAIL']], $f['user']);
    $inspection = i27Qc($f, $gr);
    $qc->finalize($inspection, [['warehouse_id' => 999, 'qty' => 1000]], $f['user']);
    expect((float) i27Balance($f, $f['staging'])->quality_hold)->toBe(0.0)
        ->and(StockLedger::where('movement_type', 'QUALITY_RELEASE')->count())->toBe(1)
        ->and(StockLedger::where('movement_type', 'QUALITY_RELEASE')->first()->lot_no)->toBe('LOT-I27');
    expect(fn () => $qc->finalize($pending, [], $f['user']))->toThrow(RuntimeException::class)
        ->and($pending->fresh()->finalized_at)->toBeNull();
});

test('I27 partially inspected fabric preserves the untouched roll quality hold', function () {
    $f = i27Fixture(true);
    [$gr, $line] = i27Receipt($f, true);
    $rolls = $line->rolls;
    i27Qc($f, $gr, 'PASS', [['gr_line_id' => $line->id, 'roll_id' => $rolls[0]->id, 'result' => 'PASS']]);
    expect($line->fresh()->status)->toBe('PARTIAL')->and($rolls[1]->fresh()->status)->toBe('QUALITY_HOLD');
    i27Qc($f, $gr, 'FAIL', [['gr_line_id' => $line->id, 'roll_id' => $rolls[1]->id, 'result' => 'FAIL']]);
    expect((float) StockBalance::where('roll_id', $rolls[1]->id)->first()->quality_hold)->toBe(5.0);
});

test('I27 supplier return stores normalized lines and posts rejected stock once without altering PO received qty', function () {
    $f = i27Fixture();
    [$gr, $line, $po] = i27Receipt($f);
    i27Qc($f, $gr, 'FAIL');
    $service = app(SupplierReturnService::class);
    $return = $service->create(1, $gr, [['gr_line_id' => $line->id, 'qty' => 999, 'unit_cost' => 999]], 'Mutu gagal', $f['user']);
    expect($return->status)->toBe('DRAFT')->and($return->doc_no)->toStartWith('SR-')
        ->and($return->lines->first()->qty)->toBe('10.0000')->and((float) i27Balance($f, $f['staging'])->on_hand)->toBe(10.0);
    $service->post($return, $f['user']);
    $service->post($return->fresh(), $f['user']);
    $ledger = StockLedger::where('movement_type', 'PURCHASE_RETURN')->firstOrFail();
    expect($return->fresh()->status)->toBe('SHIPPED')->and($return->fresh()->posted_at)->not->toBeNull()
        ->and($ledger->source_document_line_id)->toBe($return->lines->first()->id)
        ->and($ledger->location_id)->toBe($f['staging']->id)->and($ledger->lot_no)->toBe('LOT-I27')
        ->and(StockLedger::where('movement_type', 'PURCHASE_RETURN')->count())->toBe(1)
        ->and((float) i27Balance($f, $f['staging'])->quality_hold)->toBe(0.0)
        ->and((float) $po->fresh('lines')->lines->first()->received_qty)->toBe(10.0);
    expect(fn () => $service->create(1, $gr, [['gr_line_id' => $line->id]], 'Again', $f['user']))->toThrow(RuntimeException::class);
});

test('I27 supplier return requires finalized FAIL, not a status label', function () {
    $f = i27Fixture();
    [$gr, $line] = i27Receipt($f);
    $line->update(['status' => 'REJECTED_RETURNED']);
    expect(fn () => app(SupplierReturnService::class)->create(1, $gr, [['gr_line_id' => $line->id]], 'Uninspected', $f['user']))->toThrow(RuntimeException::class)
        ->and(SupplierReturn::count())->toBe(0)->and((float) i27Balance($f, $f['staging'])->quality_hold)->toBe(10.0);
});

test('I27 duplicate return units rollback and draft cancellation releases allocation only', function () {
    $f = i27Fixture();
    [$gr, $line] = i27Receipt($f);
    i27Qc($f, $gr, 'FAIL');
    $s = app(SupplierReturnService::class);
    $unit = ['gr_line_id' => $line->id];
    expect(fn () => $s->create(1, $gr, [$unit, $unit], 'Duplicate', $f['user']))->toThrow(RuntimeException::class)->and(SupplierReturn::count())->toBe(0);
    $a = $s->create(1, $gr, [$unit], 'A', $f['user']);
    expect(fn () => $s->create(1, $gr, [$unit], 'B', $f['user']))->toThrow(RuntimeException::class);
    $s->cancel($a, $f['user']);
    $b = $s->create(1, $gr, [$unit], 'B', $f['user']);
    expect($a->fresh()->status)->toBe('CANCELLED')->and($a->doc_no)->not->toBe($b->doc_no)
        ->and(StockLedger::where('movement_type', 'PURCHASE_RETURN')->count())->toBe(0);
});

test('I27 roll return cannot use whole GR line and zeros returned roll remaining quantity', function () {
    $f = i27Fixture(true);
    [$gr, $line] = i27Receipt($f, true);
    i27Qc($f, $gr, 'FAIL');
    $s = app(SupplierReturnService::class);
    expect(fn () => $s->create(1, $gr, [['gr_line_id' => $line->id]], 'No roll', $f['user']))->toThrow(RuntimeException::class);
    $roll = $line->rolls->first();
    $returned = app(InwardQcService::class)->returnGoods(1, $gr, [['gr_line_id' => $line->id, 'roll_id' => $roll->id]], 'Reject', $f['user']);
    expect($returned->status)->toBe('SHIPPED')->and($roll->fresh()->remainingUse())->toBe(0.0)
        ->and((float) StockBalance::where('roll_id', $roll->id)->first()->on_hand)->toBe(0.0);
});

test('I27 legacy return ledger is recognized even without normalized return lines', function () {
    $f = i27Fixture();
    [$gr, $line] = i27Receipt($f);
    i27Qc($f, $gr, 'FAIL');
    $stock = app(ReceiptStockService::class);
    [, , $receipt] = $stock->resolve($gr, $line->id, null, false);
    $old = SupplierReturn::create(['company_id' => 1, 'doc_no' => 'LEGACY-SR', 'goods_receipt_id' => $gr->id, 'supplier_id' => $f['supplier']->id, 'reason' => 'Old return', 'status' => 'SUBMITTED']);
    app(InventoryTransactionService::class)->post('PURCHASE_RETURN', ['company_id' => 1, 'source_document_type' => 'supplier_returns', 'source_document_id' => $old->id], [$stock->stockLine($receipt, 10, $line->id)], $f['user']);
    expect($stock->returnedQty($receipt))->toBe(10.0)
        ->and(fn () => app(SupplierReturnService::class)->create(1, $gr, [['gr_line_id' => $line->id]], 'Duplicate', $f['user']))->toThrow(RuntimeException::class);
});

test('I27 putaway posts location pairs atomically and preserves moving average valuation', function () {
    $f = i27Fixture();
    [$gr, $line] = i27Receipt($f);
    i27Qc($f, $gr);
    $its = app(InventoryTransactionService::class);
    $base = ['material_id' => $f['material']->id, 'warehouse_id' => $f['warehouse']->id, 'lot_no' => 'LOT-I27', 'uom_id' => $f['uom']->id];
    $its->post('OPENING', ['company_id' => 1, 'source_document_type' => 'i27_tests', 'source_document_id' => 1], [
        $base + ['location_id' => $f['staging']->id, 'qty' => 10, 'unit_cost' => 15],
        $base + ['location_id' => $f['rack']->id, 'qty' => 5, 'unit_cost' => 20],
    ], $f['user']);
    $p = i27Putaway($f, $gr, $line);
    $s = app(PutawayService::class);
    expect((float) i27Balance($f, $f['staging'])->on_hand)->toBe(20.0);
    $s->post($p, $f['user']);
    $s->post($p->fresh(), $f['user']);
    expect($p->fresh()->status)->toBe('POSTED')->and($p->fresh('lines')->lines->first()->unit_cost)->toBe('10.000000')
        ->and((float) i27Balance($f, $f['staging'])->on_hand)->toBe(10.0)->and((float) i27Balance($f, $f['rack'])->on_hand)->toBe(15.0)
        ->and(StockMovement::where('source_document_type', 'putaways')->count())->toBe(2)
        ->and(StockLedger::where('source_document_type', 'putaways')->sum('qty_in'))->toEqual(10)
        ->and(StockLedger::where('source_document_type', 'putaways')->sum('qty_out'))->toEqual(10);
    $value = StockBalance::where('material_id', $f['material']->id)->get()->sum(fn ($b) => (float) $b->on_hand * (float) $b->avg_cost);
    expect(abs($value - 300))->toBeLessThan(0.0001);
});

test('I27 putaway draft allocation prevents over-allocation and cancelled draft frees quantity', function () {
    $f = i27Fixture();
    [$gr, $line] = i27Receipt($f);
    i27Qc($f, $gr);
    $s = app(PutawayService::class);
    $p = i27Putaway($f, $gr, $line, 6);
    expect(fn () => i27Putaway($f, $gr, $line, 5))->toThrow(RuntimeException::class);
    $s->cancel($p, $f['user']);
    $all = i27Putaway($f, $gr, $line);
    expect($p->fresh()->status)->toBe('CANCELLED')->and($all->status)->toBe('DRAFT')
        ->and(fn () => $s->post($p, $f['user']))->toThrow(RuntimeException::class);
});

test('I27 putaway supports partial non-roll quantity and rejects over-receipt allocation', function () {
    $f = i27Fixture();
    [$gr, $line] = i27Receipt($f);
    i27Qc($f, $gr);
    $s = app(PutawayService::class);
    expect(fn () => i27Putaway($f, $gr, $line, 10.0001))->toThrow(RuntimeException::class);
    $s->post(i27Putaway($f, $gr, $line, 6), $f['user']);
    $s->post(i27Putaway($f, $gr, $line, 4), $f['user']);
    expect((float) i27Balance($f, $f['rack'])->on_hand)->toBe(10.0)->and((float) i27Balance($f, $f['staging'])->on_hand)->toBe(0.0);
});

test('I27 putaway refuses pending and failed QC', function (string $result) {
    $f = i27Fixture();
    [$gr, $line] = i27Receipt($f);
    if ($result === 'FAIL') {
        i27Qc($f, $gr, 'FAIL');
    }
    expect(fn () => i27Putaway($f, $gr, $line))->toThrow(RuntimeException::class)->and(Putaway::count())->toBe(0);
})->with(['PENDING', 'FAIL']);

test('I27 putaway rejects split fabric rolls and locations outside the receipt warehouse', function () {
    $f = i27Fixture(true);
    [$gr, $line] = i27Receipt($f, true);
    i27Qc($f, $gr);
    $s = app(PutawayService::class);
    $roll = $line->rolls->first();
    $unit = ['gr_line_id' => $line->id, 'roll_id' => $roll->id, 'qty' => 2, 'to_location_id' => $f['rack']->id];
    expect(fn () => $s->create(1, $gr, [$unit], null, $f['user']))->toThrow(RuntimeException::class);
    $otherWh = Warehouse::create(['company_id' => 1, 'code' => 'OTHER-WH', 'name' => 'Other', 'type' => 'RM']);
    $otherLoc = Location::create(['warehouse_id' => $otherWh->id, 'code' => 'X']);
    $unit['qty'] = 5;
    $unit['to_location_id'] = $otherLoc->id;
    expect(fn () => $s->create(1, $gr, [$unit], null, $f['user']))->toThrow(RuntimeException::class);
    $unit['to_location_id'] = $f['rack']->id;
    $s->post($s->create(1, $gr, [$unit], null, $f['user']), $f['user']);
    expect((float) StockBalance::where('roll_id', $roll->id)->where('location_id', $f['rack']->id)->first()->on_hand)->toBe(5.0);
});

test('I27 insufficient available stock rolls back putaway including valuation snapshot', function () {
    $f = i27Fixture();
    [$gr, $line] = i27Receipt($f);
    i27Qc($f, $gr);
    $p = i27Putaway($f, $gr, $line);
    i27Balance($f, $f['staging'])->update(['reserved' => 1]);
    expect(fn () => app(PutawayService::class)->post($p, $f['user']))->toThrow(RuntimeException::class)
        ->and($p->fresh()->status)->toBe('DRAFT')->and($p->fresh('lines')->lines->first()->unit_cost)->toBeNull()
        ->and(StockMovement::where('source_document_type', 'putaways')->count())->toBe(0)
        ->and((float) i27Balance($f, $f['staging'])->on_hand)->toBe(10.0);
});

test('I27 inbound failure rolls back the outbound location movement', function () {
    $f = i27Fixture();
    [$gr, $line] = i27Receipt($f);
    i27Qc($f, $gr);
    $p = i27Putaway($f, $gr, $line);
    $mock = Mockery::mock(app(InventoryTransactionService::class))->makePartial();
    $mock->shouldReceive('post')->with('TRANSFER_IN', Mockery::any(), Mockery::any(), Mockery::any())->andThrow(new RuntimeException('Simulated inbound failure'));
    app()->instance(InventoryTransactionService::class, $mock);
    expect(fn () => app(PutawayService::class)->post($p, $f['user']))->toThrow(RuntimeException::class, 'Simulated inbound')
        ->and($p->fresh()->status)->toBe('DRAFT')->and(StockMovement::where('source_document_type', 'putaways')->count())->toBe(0)
        ->and((float) i27Balance($f, $f['staging'])->on_hand)->toBe(10.0);
});

test('I27 trace exposes receipt source, operation line, location ledger and no prices', function () {
    $f = i27Fixture();
    [$gr, $line] = i27Receipt($f);
    i27Qc($f, $gr);
    app(PutawayService::class)->post(i27Putaway($f, $gr, $line), $f['user']);
    $trace = app(ReceivingTraceService::class)->show($gr, $f['user']);
    expect($trace['units'][0]['remaining_putaway_qty'])->toBe(0.0)->and($trace['units'][0]['location'])->toBe('DOCK')
        ->and($trace['putaways'][0]->lines[0]->toLocation->code)->toBe('RACK-A')
        ->and($trace['ledger']->total())->toBe(4)->and(json_encode($trace))->not->toContain('unit_cost', 'unit_price', 'total_amount');
});

test('I27 HTTP endpoints enforce permission and warehouse lookups mask commercial prices', function () {
    $f = i27Fixture();
    [$gr, $line, $po] = i27Receipt($f);
    $user = $f['user'];
    $user->roles()->attach(Role::where('code', 'warehouse')->firstOrFail());
    Sanctum::actingAs($user, ['api:access']);
    $this->getJson('/api/purchasing/rfqs')->assertForbidden();
    $this->getJson('/api/receiving/grs/'.$gr->id)->assertOk()->assertJsonMissingPath('lines.0.unit_price')
        ->assertJsonMissingPath('lines.0.po_line.unit_price')->assertJsonMissingPath('purchase_order.total_amount');
    $po->fresh()->update(['status' => 'APPROVED']);
    $this->getJson('/api/receiving/purchase-orders/'.$po->id)->assertOk()->assertJsonMissingPath('total_amount')->assertJsonMissingPath('lines.0.unit_price');
    $this->getJson('/api/receiving/locations?warehouse_id='.$f['warehouse']->id)->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/receiving/grs/'.$gr->id.'/stock-trace')->assertOk()->assertJsonPath('units.0.can_putaway', false);
});

test('I27 award endpoint requires both RFQ update and PO create permission', function () {
    $f = i27Fixture();
    $rfq = i27Rfq($f);
    $q = app(SourcingService::class)->addQuotation($rfq, i27QuoteData($f, $rfq), $f['user']);
    $role = Role::create(['company_id' => 1, 'code' => 'RFQ_ONLY', 'name' => 'RFQ only']);
    $role->permissions()->attach(Permission::where('code', 'purchasing.rfq.update')->firstOrFail());
    $f['user']->roles()->attach($role);
    Sanctum::actingAs($f['user'], ['api:access']);
    $path = '/api/purchasing/rfqs/'.$rfq->id.'/quotations/'.$q->id.'/award';
    $this->postJson($path, i27AwardData())->assertForbidden();
    $role->permissions()->attach(Permission::where('code', 'purchasing.po.create')->firstOrFail());
    $response = $this->postJson($path, i27AwardData())->assertOk()->assertJsonPath('status', 'DRAFT');
    $role->permissions()->attach(Permission::where('code', 'purchasing.po.view')->firstOrFail());
    $this->getJson('/api/purchasing/pos/'.$response->json('id'))->assertOk()
        ->assertJsonPath('rfq.id', $rfq->id)->assertJsonPath('quotation.id', $q->id)
        ->assertJsonPath('lines.0.quotation_line.id', $q->lines->first()->id);
});

test('I27 new receiving services reject foreign company even on replay', function () {
    $f = i27Fixture();
    [$gr, $line] = i27Receipt($f);
    i27Qc($f, $gr);
    $p = i27Putaway($f, $gr, $line);
    app(PutawayService::class)->post($p, $f['user']);
    $company = Company::create(['code' => 'I27-FOREIGN', 'name' => 'Foreign', 'base_currency' => 'USD']);
    $foreign = User::factory()->create(['company_id' => $company->id]);
    expect(fn () => app(PutawayService::class)->post($p, $foreign))->toThrow(RuntimeException::class)
        ->and(fn () => app(ReceivingTraceService::class)->show($gr, $foreign))->toThrow(RuntimeException::class);
});
