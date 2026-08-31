<?php

use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockLedger;
use Modules\Inventory\Services\InventoryOpsService;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Uom;
use Modules\MasterData\Models\Warehouse;

function stage4OpsFixture(): array
{
    $user = User::factory()->create(['company_id' => 1]);
    $material = Material::create(['company_id' => 1, 'code' => 'OPS-'.uniqid(), 'name' => 'Thread', 'type' => 'TRIM', 'tracking_level' => 'LOT']);
    $uom = Uom::create(['company_id' => 1, 'code' => 'CON'.substr(uniqid(), -4), 'name' => 'Cone']);
    $from = Warehouse::create(['company_id' => 1, 'code' => 'F'.substr(uniqid(), -6), 'name' => 'From', 'type' => 'RM']);
    $to = Warehouse::create(['company_id' => 1, 'code' => 'T'.substr(uniqid(), -6), 'name' => 'To', 'type' => 'RM']);
    app(InventoryTransactionService::class)->post('OPENING', [
        'company_id' => 1, 'source_document_type' => 'tests', 'source_document_id' => 401,
    ], [[
        'material_id' => $material->id, 'warehouse_id' => $from->id,
        'qty' => 100, 'uom_id' => $uom->id, 'unit_cost' => 12.5,
    ]], $user);

    return compact('user', 'material', 'uom', 'from', 'to');
}

test('transfer mempertahankan moving average cost di gudang tujuan', function () {
    $f = stage4OpsFixture();
    $service = app(InventoryOpsService::class);
    $transfer = $service->createTransfer(1, [
        'from_warehouse_id' => $f['from']->id, 'to_warehouse_id' => $f['to']->id,
    ], [[
        'material_id' => $f['material']->id, 'qty' => 40, 'uom_id' => $f['uom']->id,
    ]], $f['user']);

    $service->postTransfer($transfer, $f['user']);
    $service->receiveTransfer($transfer->fresh(), $f['user']);

    $destination = StockBalance::withoutGlobalScopes()
        ->where('material_id', $f['material']->id)->where('warehouse_id', $f['to']->id)->firstOrFail();
    expect((float) $destination->on_hand)->toBe(40.0)
        ->and((float) $destination->avg_cost)->toBe(12.5);
});

test('apply adjustment idempotent di bawah document lock', function () {
    $f = stage4OpsFixture();
    $service = app(InventoryOpsService::class);
    $adjustment = $service->createAdjustment(1, 'Correction', [[
        'material_id' => $f['material']->id, 'warehouse_id' => $f['from']->id,
        'qty_delta' => -5, 'uom_id' => $f['uom']->id,
    ]], $f['user']);
    $adjustment->update(['status' => 'SUBMITTED']);

    $service->applyAdjustmentOnApproval($adjustment->id);
    $service->applyAdjustmentOnApproval($adjustment->id);

    $balance = StockBalance::withoutGlobalScopes()->where('material_id', $f['material']->id)->where('warehouse_id', $f['from']->id)->firstOrFail();
    expect((float) $balance->on_hand)->toBe(95.0)
        ->and($adjustment->fresh()->status)->toBe('APPROVED')
        ->and(StockLedger::withoutGlobalScopes()->where('source_document_type', 'stock_adjustments')->where('source_document_id', $adjustment->id)->count())->toBe(1);
});

test('submit adjustment rollback ke DRAFT bila approval gagal', function () {
    $f = stage4OpsFixture();
    $approval = Mockery::mock(ApprovalEngine::class);
    $approval->shouldReceive('submit')->once()->andThrow(new RuntimeException('approval unavailable'));
    $service = new InventoryOpsService(
        app(NumberingService::class), app(InventoryTransactionService::class),
        $approval, app(AuditService::class),
    );
    $adjustment = $service->createAdjustment(1, 'Correction', [[
        'material_id' => $f['material']->id, 'warehouse_id' => $f['from']->id,
        'qty_delta' => -5, 'uom_id' => $f['uom']->id,
    ]], $f['user']);

    expect(fn () => $service->submitAdjustment($adjustment, $f['user']))
        ->toThrow(RuntimeException::class, 'approval unavailable');
    expect($adjustment->fresh()->status)->toBe('DRAFT');
});

test('opname menolak submit bila tidak semua line dihitung', function () {
    $f = stage4OpsFixture();
    $service = app(InventoryOpsService::class);
    $opname = $service->createOpname(1, $f['from']->id, $f['user']);

    expect(fn () => $service->recordCountsAndSubmit($opname, [], $f['user']))
        ->toThrow(RuntimeException::class, 'Seluruh line');
    expect($opname->fresh()->status)->toBe('COUNTING');
});
