<?php

use Modules\Core\Models\User;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockLedger;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Supplier;
use Modules\MasterData\Models\Uom;
use Modules\MasterData\Models\Warehouse;
use Modules\Purchasing\Models\SupplierInvoice;
use Modules\Purchasing\Services\PurchasingService;
use Modules\Purchasing\Services\ThreeWayMatchService;
use Modules\Receiving\Services\InwardQcService;
use Modules\Receiving\Services\ReceivingService;

function stage4MatchFixture(): array
{
    $user = User::factory()->create(['company_id' => 1]);
    $material = Material::create([
        'company_id' => 1, 'code' => 'MATCH-'.uniqid(), 'name' => 'Carton',
        'type' => 'PACKAGING', 'tracking_level' => 'LOT',
    ]);
    $uom = Uom::create(['company_id' => 1, 'code' => 'BOX'.substr(uniqid(), -4), 'name' => 'Box']);
    $warehouse = Warehouse::create(['company_id' => 1, 'code' => 'WH'.substr(uniqid(), -5), 'name' => 'RM', 'type' => 'RM']);
    $supplier = Supplier::create(['company_id' => 1, 'code' => 'SUP'.substr(uniqid(), -5), 'name' => 'Vendor', 'type' => 'TRIM']);
    $po = app(PurchasingService::class)->createPo(1, [
        'supplier_id' => $supplier->id, 'order_date' => '2026-08-31',
    ], [[
        'material_id' => $material->id, 'qty' => 10, 'uom_id' => $uom->id, 'unit_price' => 5,
    ]], $user);
    $po->update(['status' => 'APPROVED']);

    return compact('user', 'material', 'uom', 'warehouse', 'supplier', 'po');
}

test('3-way match menghasilkan MISMATCH bila PO belum pernah diterima', function () {
    $f = stage4MatchFixture();
    $invoice = SupplierInvoice::create([
        'company_id' => 1, 'doc_no' => 'NO-GR-'.uniqid(), 'supplier_id' => $f['supplier']->id,
        'purchase_order_id' => $f['po']->id, 'invoice_date' => '2026-08-31',
        'total_amount' => 50, 'created_by' => $f['user']->id,
    ]);
    $invoice->lines()->create([
        'po_line_id' => $f['po']->lines->first()->id,
        'qty' => 10, 'unit_price' => 5, 'amount' => 50,
    ]);

    expect(app(ThreeWayMatchService::class)->match($invoice, 2, 2)->match_status)->toBe('MISMATCH');
});

test('finalize inward QC idempotent dan tidak melepas hold dua kali', function () {
    $f = stage4MatchFixture();
    $gr = app(ReceivingService::class)->createAndPost(1, [
        'purchase_order_id' => $f['po']->id,
        'warehouse_id' => $f['warehouse']->id,
        'received_date' => '2026-08-31',
    ], [[
        'po_line_id' => $f['po']->lines->first()->id,
        'qty_received' => 10,
    ]], $f['user']);

    $grLine = $gr->lines->first();
    $qc = app(InwardQcService::class);
    $inspection = $qc->create(1, $gr, [[
        'gr_line_id' => $grLine->id,
        'result' => 'PASS',
    ]], $f['user']);

    $qc->finalize($inspection, [], $f['user']);
    $qc->finalize($inspection->fresh(), [], $f['user']);

    $balance = StockBalance::withoutGlobalScopes()->where('material_id', $f['material']->id)->firstOrFail();
    expect((float) $balance->quality_hold)->toBe(0.0)
        ->and($balance->available())->toBe(10.0)
        ->and(StockLedger::withoutGlobalScopes()->where('movement_type', 'QUALITY_RELEASE')->count())->toBe(1)
        ->and($inspection->fresh()->finalized_at)->not->toBeNull();
});
