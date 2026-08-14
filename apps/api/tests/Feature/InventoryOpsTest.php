<?php

use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Models\ApprovalFlow;
use Modules\Core\Models\ApprovalFlowStep;
use Modules\Core\Models\ApprovalRequest;
use Modules\Core\Approval\ApprovalEngine;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockLedger;
use Modules\Inventory\Services\InventoryOpsService;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Uom;
use Modules\MasterData\Models\Warehouse;

/** Helper unik untuk file ini (tidak redeclare dengan file test lain) */
function opsFixture(float $qty = 100): array
{
    $user = User::factory()->create(['company_id' => 1]);
    $material = Material::create(['company_id' => 1, 'code' => 'FAB-'.uniqid(), 'name' => 'Kain', 'type' => 'FABRIC', 'tracking_level' => 'LOT']);
    $uom = Uom::create(['company_id' => 1, 'code' => 'MTR'.substr(uniqid(), -3), 'name' => 'Meter']);
    $whA = Warehouse::create(['company_id' => 1, 'code' => 'RMA-'.substr(uniqid(), -2), 'name' => 'RM A', 'type' => 'RM']);
    $whB = Warehouse::create(['company_id' => 1, 'code' => 'RMB-'.substr(uniqid(), -2), 'name' => 'RM B', 'type' => 'RM']);

    app(InventoryTransactionService::class)->post('OPENING', [
        'company_id' => 1, 'source_document_type' => 'tests', 'source_document_id' => 1,
    ], [['material_id' => $material->id, 'warehouse_id' => $whA->id, 'qty' => $qty, 'uom_id' => $uom->id, 'unit_cost' => 10]], $user);

    return [$user, $material, $uom, $whA, $whB];
}

function opsBalance(int $materialId, int $warehouseId): ?StockBalance
{
    return StockBalance::withoutGlobalScopes()
        ->where('material_id', $materialId)->where('warehouse_id', $warehouseId)->first();
}

function opsApprover(string $roleCode): User
{
    $user = User::factory()->create(['company_id' => 1]);
    $role = Role::create(['company_id' => 1, 'code' => $roleCode.'_'.substr(uniqid(), -4), 'name' => $roleCode]);
    $user->roles()->sync([$role->id]);

    return $user;
}

function opsMakeFlow(string $docType, string $roleCode): void
{
    $role = Role::where('company_id', 1)->where('name', $roleCode)->first()
        ?? Role::create(['company_id' => 1, 'code' => strtolower($roleCode).'_flow', 'name' => $roleCode]);
    $flow = ApprovalFlow::create(['company_id' => 1, 'doc_type' => $docType, 'version' => 1, 'mode' => 'sequential', 'is_active' => true]);
    ApprovalFlowStep::create(['flow_id' => $flow->id, 'step_no' => 1, 'role_id' => $role->id]);
}

test('transfer: OUT dari gudang asal lalu IN ke tujuan — saldo benar dua sisi', function () {
    [$user, $material, $uom, $whA, $whB] = opsFixture(100);

    $svc = app(InventoryOpsService::class);
    $transfer = $svc->createTransfer(1, ['from_warehouse_id' => $whA->id, 'to_warehouse_id' => $whB->id], [
        ['material_id' => $material->id, 'qty' => 30, 'uom_id' => $uom->id],
    ], $user);

    $svc->postTransfer($transfer, $user);
    expect((float) opsBalance($material->id, $whA->id)->on_hand)->toBe(70.0);
    expect(opsBalance($material->id, $whB->id))->toBeNull();   // belum diterima
    expect($transfer->fresh()->status)->toBe('IN_TRANSIT');

    $svc->receiveTransfer($transfer->fresh(), $user);
    expect((float) opsBalance($material->id, $whB->id)->on_hand)->toBe(30.0);
    expect($transfer->fresh()->status)->toBe('RECEIVED');

    // Ledger mencatat dua sisi
    $types = StockLedger::withoutGlobalScopes()->where('source_document_type', 'stock_transfers')->pluck('movement_type')->all();
    expect($types)->toContain('TRANSFER_OUT');
    expect($types)->toContain('TRANSFER_IN');
});

test('BR-017: adjustment tidak berefek sebelum approval; setelah APPROVED saldo terkoreksi via ITS', function () {
    [$user, $material, $uom, $whA] = opsFixture(100);
    opsMakeFlow('ADJ', 'Warehouse Manager');

    $svc = app(InventoryOpsService::class);
    $adj = $svc->createAdjustment(1, 'Selisih hitung', [
        ['material_id' => $material->id, 'warehouse_id' => $whA->id, 'qty_delta' => -5, 'uom_id' => $uom->id],
    ], $user);
    $svc->submitAdjustment($adj, $user);

    // Belum approved → saldo tetap 100
    expect((float) opsBalance($material->id, $whA->id)->on_hand)->toBe(100.0);

    // Approve via engine → listener menerapkan ke stok
    $req = ApprovalRequest::withoutGlobalScopes()->where('doc_type', 'ADJ')->where('doc_id', $adj->id)->firstOrFail();
    app(ApprovalEngine::class)->approve($req, opsApprover('Warehouse Manager'));

    expect((float) opsBalance($material->id, $whA->id)->on_hand)->toBe(95.0);
    $entry = StockLedger::withoutGlobalScopes()->where('movement_type', 'ADJUSTMENT')->where('source_document_id', $adj->id)->firstOrFail();
    expect((float) $entry->qty_out)->toBe(5.0);
});

test('opname: freeze → count → variance → approval → OPNAME_ADJUSTMENT', function () {
    [$user, $material, $uom, $whA] = opsFixture(100);
    opsMakeFlow('OPN', 'Warehouse Manager');

    $svc = app(InventoryOpsService::class);
    $opname = $svc->createOpname(1, $whA->id, $user);

    expect($opname->status)->toBe('COUNTING');
    $line = $opname->lines->first();
    expect((float) $line->system_qty)->toBe(100.0);

    $opname = $svc->recordCountsAndSubmit($opname, [['line_id' => $line->id, 'counted_qty' => 97]], $user);
    expect((float) $opname->lines->first()->variance_qty)->toBe(-3.0);

    // Belum approved → saldo tetap
    expect((float) opsBalance($material->id, $whA->id)->on_hand)->toBe(100.0);

    $req = ApprovalRequest::withoutGlobalScopes()->where('doc_type', 'OPN')->where('doc_id', $opname->id)->firstOrFail();
    app(ApprovalEngine::class)->approve($req, opsApprover('Warehouse Manager'));

    expect((float) opsBalance($material->id, $whA->id)->on_hand)->toBe(97.0);
    expect(StockLedger::withoutGlobalScopes()->where('movement_type', 'OPNAME_ADJUSTMENT')->exists())->toBeTrue();
});
