<?php

use Modules\Core\Models\Company;
use Modules\Core\Models\User;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockLedger;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Uom;
use Modules\MasterData\Models\Warehouse;

it('ITS menolak material lintas company sebelum menulis ledger atau saldo', function () {
    $user = User::factory()->create(['company_id' => 1]);
    $otherCompany = Company::create(['code' => 'OTHER-'.uniqid(), 'name' => 'Other Company']);
    $foreignMaterial = Material::create([
        'company_id' => $otherCompany->id,
        'code' => 'FOREIGN-'.uniqid(),
        'name' => 'Foreign Material',
        'type' => 'TRIM',
        'tracking_level' => 'LOT',
    ]);
    $uom = Uom::create(['company_id' => 1, 'code' => 'EA'.substr(uniqid(), -5), 'name' => 'Each']);
    $warehouse = Warehouse::create(['company_id' => 1, 'code' => 'WH'.substr(uniqid(), -5), 'name' => 'Warehouse', 'type' => 'RM']);

    expect(fn () => app(InventoryTransactionService::class)->post('OPENING', [
        'company_id' => 1,
        'source_document_type' => 'tests',
        'source_document_id' => 999,
    ], [[
        'material_id' => $foreignMaterial->id,
        'warehouse_id' => $warehouse->id,
        'qty' => 1,
        'uom_id' => $uom->id,
    ]], $user))->toThrow(RuntimeException::class, 'material tidak ditemukan');

    expect(StockLedger::withoutGlobalScopes()->count())->toBe(0)
        ->and(StockBalance::withoutGlobalScopes()->count())->toBe(0);
});
