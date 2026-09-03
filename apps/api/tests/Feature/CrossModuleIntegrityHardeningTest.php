<?php

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Finance\Models\AccountMapping;
use Modules\Finance\Services\GlPostingService;
use Modules\Finance\Services\JournalService;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockLedger;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\ChartOfAccount;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Uom;
use Modules\MasterData\Models\Warehouse;

function iteration16InventoryFixture(): array
{
    $user = User::factory()->create(['company_id' => 1]);
    $material = Material::create([
        'company_id' => 1, 'code' => 'I16-MAT-'.uniqid(), 'name' => 'Iteration 16 Material',
        'type' => 'TRIM', 'tracking_level' => 'LOT', 'is_active' => true,
    ]);
    $uom = Uom::create(['company_id' => 1, 'code' => 'I16-'.substr(uniqid(), -5), 'name' => 'Each']);
    $warehouse = Warehouse::create([
        'company_id' => 1, 'code' => 'I16-WH-'.substr(uniqid(), -5),
        'name' => 'Iteration 16 Warehouse', 'type' => 'RM', 'is_active' => true,
    ]);
    return [$user, $material, $uom, $warehouse, app(InventoryTransactionService::class)];
}

it('returns the original ITS movement for an identical deterministic replay', function () {
    [$user, $material, $uom, $warehouse, $its] = iteration16InventoryFixture();
    $header = ['company_id' => 1, 'source_document_type' => 'iteration_16_tests', 'source_document_id' => 16001];
    $lines = [[
        'material_id' => $material->id, 'warehouse_id' => $warehouse->id, 'qty' => 5,
        'uom_id' => $uom->id, 'unit_cost' => 10, 'source_document_line_id' => 1,
    ]];
    $first = $its->post('OPENING', $header, $lines, $user);
    $replay = $its->post('OPENING', $header, $lines, $user);
    $balance = StockBalance::withoutGlobalScopes()->where('material_id', $material->id)->where('warehouse_id', $warehouse->id)->firstOrFail();
    expect($replay->id)->toBe($first->id)
        ->and((float) $balance->on_hand)->toBe(5.0)
        ->and(StockMovement::withoutGlobalScopes()->where('source_document_type', 'iteration_16_tests')->where('source_document_id', 16001)->count())->toBe(1)
        ->and(StockLedger::withoutGlobalScopes()->where('source_document_type', 'iteration_16_tests')->where('source_document_id', 16001)->count())->toBe(1);
});

it('rejects a divergent ITS replay without a duplicate movement ledger or balance mutation', function () {
    [$user, $material, $uom, $warehouse, $its] = iteration16InventoryFixture();
    $header = ['company_id' => 1, 'source_document_type' => 'iteration_16_tests', 'source_document_id' => 16002];
    $base = [[
        'material_id' => $material->id, 'warehouse_id' => $warehouse->id, 'qty' => 5,
        'uom_id' => $uom->id, 'unit_cost' => 10, 'source_document_line_id' => 1,
    ]];
    $its->post('OPENING', $header, $base, $user);
    $divergent = $base;
    $divergent[0]['qty'] = 6;
    expect(fn () => $its->post('OPENING', $header, $divergent, $user))
        ->toThrow(RuntimeException::class, 'ITS_IDEMPOTENCY_CONFLICT');
    $balance = StockBalance::withoutGlobalScopes()->where('material_id', $material->id)->where('warehouse_id', $warehouse->id)->firstOrFail();
    expect((float) $balance->on_hand)->toBe(5.0)
        ->and(StockMovement::withoutGlobalScopes()->where('source_document_type', 'iteration_16_tests')->where('source_document_id', 16002)->count())->toBe(1)
        ->and(StockLedger::withoutGlobalScopes()->where('source_document_type', 'iteration_16_tests')->where('source_document_id', 16002)->count())->toBe(1);
});

it('blocks ITS writes for an inactive company before any movement or ledger is created', function () {
    [$user, $material, $uom, $warehouse, $its] = iteration16InventoryFixture();
    DB::table('companies')->where('id', 1)->update(['is_active' => false]);
    expect(fn () => $its->post('OPENING', [
        'company_id' => 1, 'source_document_type' => 'iteration_16_tests', 'source_document_id' => 16003,
    ], [[
        'material_id' => $material->id, 'warehouse_id' => $warehouse->id, 'qty' => 1, 'uom_id' => $uom->id,
    ]], $user))->toThrow(RuntimeException::class, 'company movement tidak aktif');
    expect(StockMovement::withoutGlobalScopes()->where('source_document_id', 16003)->count())->toBe(0)
        ->and(StockLedger::withoutGlobalScopes()->where('source_document_id', 16003)->count())->toBe(0);
});

it('blocks ITS writes to an inactive warehouse', function () {
    [$user, $material, $uom, $warehouse, $its] = iteration16InventoryFixture();
    $warehouse->update(['is_active' => false]);
    expect(fn () => $its->post('OPENING', [
        'company_id' => 1, 'source_document_type' => 'iteration_16_tests', 'source_document_id' => 16004,
    ], [[
        'material_id' => $material->id, 'warehouse_id' => $warehouse->id, 'qty' => 1, 'uom_id' => $uom->id,
    ]], $user))->toThrow(RuntimeException::class, 'warehouse aktif');
});

it('blocks manual journal posting for an inactive company without creating a journal', function () {
    $user = User::factory()->create(['company_id' => 1]);
    $debit = ChartOfAccount::create([
        'company_id' => 1, 'code' => 'I16-DR-'.substr(uniqid(), -4), 'name' => 'Iteration 16 Debit',
        'type' => 'ASSET', 'normal_balance' => 'DEBIT', 'is_active' => true,
    ]);
    $credit = ChartOfAccount::create([
        'company_id' => 1, 'code' => 'I16-CR-'.substr(uniqid(), -4), 'name' => 'Iteration 16 Credit',
        'type' => 'REVENUE', 'normal_balance' => 'CREDIT', 'is_active' => true,
    ]);
    DB::table('companies')->where('id', 1)->update(['is_active' => false]);
    expect(fn () => app(JournalService::class)->post(1, [
        'period' => '2026-09', 'journal_date' => '2026-09-03',
    ], [
        ['coa_id' => $debit->id, 'debit' => 100], ['coa_id' => $credit->id, 'credit' => 100],
    ], $user))->toThrow(RuntimeException::class, 'Company Finance tidak aktif');
    expect(DB::table('journals')->count())->toBe(0)->and(DB::table('journal_lines')->count())->toBe(0);
});

it('returns identical GL replay and rejects period or date substitution for the same source', function () {
    $user = User::factory()->create(['company_id' => 1]);
    $debit = ChartOfAccount::create([
        'company_id' => 1, 'code' => 'I16-GL-DR-'.substr(uniqid(), -4), 'name' => 'Inventory',
        'type' => 'ASSET', 'normal_balance' => 'DEBIT', 'is_active' => true,
    ]);
    $credit = ChartOfAccount::create([
        'company_id' => 1, 'code' => 'I16-GL-CR-'.substr(uniqid(), -4), 'name' => 'Accrual',
        'type' => 'LIABILITY', 'normal_balance' => 'CREDIT', 'is_active' => true,
    ]);
    AccountMapping::create([
        'company_id' => 1, 'event' => 'GR_RECEIPT',
        'debit_account_id' => $debit->id, 'credit_account_id' => $credit->id,
    ]);
    $service = app(GlPostingService::class);
    $first = $service->postEvent(1, 'GR_RECEIPT', 'iteration_16_sources', 16005, 100, '2026-09', $user, journalDate: '2026-09-03');
    $replay = $service->postEvent(1, 'GR_RECEIPT', 'iteration_16_sources', 16005, 100, '2026-09', $user, journalDate: '2026-09-03');
    expect($first['created'])->toBeTrue()->and($replay['created'])->toBeFalse()->and($replay['journal']->id)->toBe($first['journal']->id);
    expect(fn () => $service->postEvent(1, 'GR_RECEIPT', 'iteration_16_sources', 16005, 100, '2026-10', $user, journalDate: '2026-10-01'))
        ->toThrow(RuntimeException::class, 'GL_IDEMPOTENCY_CONFLICT');
    expect(DB::table('journals')->where('source_document_type', 'iteration_16_sources')->where('source_document_id', 16005)->count())->toBe(1);
});

it('keeps existing authority and regression coverage explicit for Iteration 16', function () {
    $files = [
        'OperationalConvergenceTest.php', 'PackingShipmentTest.php', 'FgWarehouseShipmentTraceabilityTest.php',
        'OperationalGlPostingTest.php', 'JournalServiceTest.php', 'ProductionOutputAuthorityTest.php',
        'ActualCostTraceabilityTest.php', 'WipFgCogsBoundaryTest.php', 'PermissionMiddlewareTest.php',
    ];
    foreach ($files as $file) expect(file_exists(base_path('tests/Feature/'.$file)))->toBeTrue();
});
