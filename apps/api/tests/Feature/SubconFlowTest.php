<?php

use Modules\Core\Models\User;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Supplier;
use Modules\MasterData\Models\Uom;
use Modules\MasterData\Models\Warehouse;
use Modules\Subcon\Services\SubconService;

test('BR-090/091: subcon OUT menaikkan in_transit; receive menurunkannya + fee tercatat (BR-080)', function () {
    [$user, , , , $mo] = qcFixture();

    $subcon = Supplier::create(['company_id' => 1, 'code' => 'SUB-'.uniqid(), 'name' => 'CMT Jaya', 'type' => 'SUBCON']);
    $nonSubcon = Supplier::create(['company_id' => 1, 'code' => 'FAB-'.uniqid(), 'name' => 'Tex', 'type' => 'FABRIC']);
    $uom = Uom::create(['company_id' => 1, 'code' => 'PCS'.substr(uniqid(), -3), 'name' => 'Pcs']);
    $trim = Material::create(['company_id' => 1, 'code' => 'TRM-'.uniqid(), 'name' => 'Kancing', 'type' => 'TRIM', 'tracking_level' => 'LOT']);
    $warehouse = Warehouse::create(['company_id' => 1, 'code' => 'RM-'.substr(uniqid(), -3), 'name' => 'RM', 'type' => 'RM']);

    // Stok trim 500
    app(InventoryTransactionService::class)->post('OPENING', [
        'company_id' => 1, 'source_document_type' => 'tests', 'source_document_id' => 1,
    ], [['material_id' => $trim->id, 'warehouse_id' => $warehouse->id, 'qty' => 500, 'uom_id' => $uom->id, 'unit_cost' => 0.2]], $user);

    $svc = app(SubconService::class);

    // Supplier bukan SUBCON → ditolak
    try {
        $svc->createAndSend(1, $mo, $nonSubcon->id, [['material_id' => $trim->id, 'qty_sent' => 100, 'uom_id' => $uom->id]], ['warehouse_id' => $warehouse->id, 'fee_per_pcs' => 0.5], $user);
        $this->fail('Supplier non-SUBCON harus ditolak');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('SUBCON');
    }

    // Kirim 100 ke subcon → SUBCON_OUT (in_transit ↑)
    $order = $svc->createAndSend(1, $mo, $subcon->id, [['material_id' => $trim->id, 'qty_sent' => 100, 'uom_id' => $uom->id]], ['warehouse_id' => $warehouse->id, 'fee_per_pcs' => 0.5], $user);

    expect($order->status)->toBe('SENT');
    expect($order->doc_no)->toStartWith('JW-');

    $b = StockBalance::withoutGlobalScopes()->where('material_id', $trim->id)->where('warehouse_id', $warehouse->id)->firstOrFail();
    expect((float) $b->on_hand)->toBe(400.0);
    expect((float) $b->in_transit_subcon)->toBe(100.0);   // BR-090

    // Receive parsial 60 → SUBCON_IN + fee 60 × 0.5 = 30
    $order = $svc->receive($order->fresh(), [[
        'line_id' => $order->lines->first()->id, 'qty_returned' => 60, 'warehouse_id' => $warehouse->id,
    ]], $user);

    expect($order->status)->toBe('PARTIAL_RETURNED');
    $b = StockBalance::withoutGlobalScopes()->where('material_id', $trim->id)->where('warehouse_id', $warehouse->id)->firstOrFail();
    expect((float) $b->on_hand)->toBe(460.0);
    expect((float) $b->in_transit_subcon)->toBe(40.0);
    expect((float) $order->fees->first()->total_fee)->toBe(30.0);   // BR-080

    // Over-return → ditolak
    try {
        $svc->receive($order->fresh(), [[
            'line_id' => $order->lines->first()->id, 'qty_returned' => 41, 'warehouse_id' => $warehouse->id,
        ]], $user);
        $this->fail('Over-return harus ditolak');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('melebihi sisa');
    }

    // Receive sisa 40 → RETURNED
    $order = $svc->receive($order->fresh(), [[
        'line_id' => $order->lines->first()->id, 'qty_returned' => 40, 'warehouse_id' => $warehouse->id,
    ]], $user);
    expect($order->status)->toBe('RETURNED');
    expect((float) StockBalance::withoutGlobalScopes()->where('material_id', $trim->id)->where('warehouse_id', $warehouse->id)->firstOrFail()->in_transit_subcon)->toBe(0.0);
});
