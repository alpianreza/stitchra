<?php

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Finance\Models\AccountMapping;
use Modules\Finance\Models\GlPeriod;
use Modules\Finance\Models\Journal;
use Modules\Finance\Services\JournalService;
use Modules\Finance\Services\OperationalPostingService;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\ChartOfAccount;
use Modules\MasterData\Models\Currency;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Supplier;
use Modules\MasterData\Models\Uom;
use Modules\MasterData\Models\Warehouse;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Receiving\Models\GoodsReceipt;

function operationalGlFixture(string $receivedDate = '2026-09-03'): array
{
    $user = User::factory()->create(['company_id' => 1]);
    $currency = Currency::withoutGlobalScopes()->firstOrCreate(['company_id' => 1, 'code' => 'IDR'], ['name' => 'Rupiah', 'symbol' => 'Rp']);
    $supplier = Supplier::create(['company_id' => 1, 'code' => 'SUP-'.uniqid(), 'name' => 'Supplier', 'type' => 'FABRIC']);
    $warehouse = Warehouse::create(['company_id' => 1, 'code' => 'RM-'.substr(uniqid(), -4), 'name' => 'Raw Material', 'type' => 'RM']);
    $uom = Uom::create(['company_id' => 1, 'code' => 'M-'.substr(uniqid(), -4), 'name' => 'Meter']);
    $material = Material::create(['company_id' => 1, 'code' => 'FAB-'.uniqid(), 'name' => 'Fabric', 'type' => 'FABRIC', 'tracking_level' => 'LOT']);
    $po = PurchaseOrder::create([
        'company_id' => 1, 'doc_no' => 'PO-GL-'.uniqid(), 'supplier_id' => $supplier->id,
        'currency_id' => $currency->id, 'exchange_rate' => 1, 'order_date' => $receivedDate,
        'total_amount' => 1000, 'status' => 'APPROVED', 'created_by' => $user->id,
    ]);
    $gr = GoodsReceipt::create([
        'company_id' => 1, 'doc_no' => 'GR-GL-'.uniqid(), 'purchase_order_id' => $po->id,
        'warehouse_id' => $warehouse->id, 'received_date' => $receivedDate,
        'status' => 'POSTED', 'created_by' => $user->id,
    ]);
    app(InventoryTransactionService::class)->post('PURCHASE_RECEIPT', [
        'company_id' => 1, 'source_document_type' => 'goods_receipts', 'source_document_id' => $gr->id,
    ], [[
        'material_id' => $material->id, 'warehouse_id' => $warehouse->id,
        'lot_no' => 'LOT-GL', 'qty' => 100, 'uom_id' => $uom->id, 'unit_cost' => 10,
    ]], $user);
    $inventory = ChartOfAccount::create(['company_id' => 1, 'code' => 'INV-'.uniqid(), 'name' => 'Inventory', 'type' => 'ASSET', 'normal_balance' => 'DEBIT']);
    $accrual = ChartOfAccount::create(['company_id' => 1, 'code' => 'ACC-'.uniqid(), 'name' => 'Accrued AP', 'type' => 'LIABILITY', 'normal_balance' => 'CREDIT']);
    AccountMapping::create(['company_id' => 1, 'event' => 'GR_RECEIPT', 'debit_account_id' => $inventory->id, 'credit_account_id' => $accrual->id]);
    return [$user, $gr, $inventory, $accrual];
}

test('BR-101 base-currency valued GR creates one traceable AUTO journal', function () {
    [$user, $gr] = operationalGlFixture();
    $service = app(OperationalPostingService::class);
    $first = $service->postGoodsReceipt($gr, $user);
    $second = $service->postGoodsReceipt($gr->fresh(), $user);

    expect($first['created'])->toBeTrue()
        ->and($first['amount'])->toBe(1000.0)
        ->and($first['journal']->event)->toBe('GR_RECEIPT')
        ->and($first['journal']->source)->toBe('AUTO')
        ->and($first['journal']->journal_date->toDateString())->toBe('2026-09-03')
        ->and($second['created'])->toBeFalse()
        ->and($second['journal']->id)->toBe($first['journal']->id)
        ->and(Journal::withoutGlobalScopes()->where('event', 'GR_RECEIPT')->count())->toBe(1);

    $lineage = $service->journalLineage($first['journal'], $user);
    expect($lineage['operational_source']['doc_no'])->toBe($gr->doc_no)
        ->and($lineage['operational_source']['its_movement']['movement_type'])->toBe('PURCHASE_RECEIPT')
        ->and($lineage['gl_period']['period'])->toBe('2026-09');
});

test('operational posting blocks wrong or inactive company', function () {
    [$user, $gr] = operationalGlFixture();
    $otherCompany = DB::table('companies')->insertGetId(['code' => 'OTHER-'.uniqid(), 'name' => 'Other', 'base_currency' => 'IDR', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $other = User::factory()->create(['company_id' => $otherCompany]);
    expect(fn () => app(OperationalPostingService::class)->postGoodsReceipt($gr, $other))->toThrow(RuntimeException::class, 'akses');

    DB::table('companies')->where('id', 1)->update(['is_active' => false]);
    expect(fn () => app(OperationalPostingService::class)->postGoodsReceipt($gr, $user))->toThrow(RuntimeException::class, 'tidak aktif');
});

test('closed source period blocks GR posting and late transaction is not moved', function () {
    [$user, $gr] = operationalGlFixture('2026-08-31');
    GlPeriod::withoutGlobalScopes()->create(['company_id' => 1, 'period' => '2026-08', 'status' => 'CLOSED']);
    expect(fn () => app(OperationalPostingService::class)->postGoodsReceipt($gr, $user))
        ->toThrow(RuntimeException::class, 'CLOSED');
    expect(Journal::withoutGlobalScopes()->where('event', 'GR_RECEIPT')->count())->toBe(0);
});

test('foreign-currency GR and invalid source remain blocked without invented FX', function () {
    [$user, $gr] = operationalGlFixture();
    $usd = Currency::create(['company_id' => 1, 'code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$']);
    $gr->purchaseOrder()->update(['currency_id' => $usd->id, 'exchange_rate' => 15000]);
    expect(fn () => app(OperationalPostingService::class)->postGoodsReceipt($gr, $user))
        ->toThrow(RuntimeException::class, 'FX ACCOUNTING = NOT DEFINED');

    $gr->purchaseOrder()->update(['currency_id' => Currency::withoutGlobalScopes()->where('company_id', 1)->where('code', 'IDR')->value('id'), 'exchange_rate' => 1]);
    DB::table('stock_movements')->where('source_document_type', 'goods_receipts')->where('source_document_id', $gr->id)->delete();
    expect(fn () => app(OperationalPostingService::class)->postGoodsReceipt($gr, $user))
        ->toThrow(RuntimeException::class, 'tepat satu ITS');
});

test('existing append-only reversal remains linked and cannot duplicate', function () {
    [$user, $gr] = operationalGlFixture();
    $operational = app(OperationalPostingService::class);
    $original = $operational->postGoodsReceipt($gr, $user)['journal'];
    $reversal = app(JournalService::class)->reverse($original, $user, 'GR accounting correction');

    expect($original->fresh()->status)->toBe('VOID')
        ->and($reversal->reverses_journal_id)->toBe($original->id)
        ->and((float) $reversal->lines->first()->credit + (float) $reversal->lines->first()->debit)->toBe(1000.0);
    $lineage = $operational->journalLineage($original->fresh(), $user);
    expect($lineage['reversal']['id'])->toBe($reversal->id)
        ->and($lineage['operational_source']['id'])->toBe($gr->id);

    expect(fn () => app(JournalService::class)->reverse($original->fresh(), $user))
        ->toThrow(RuntimeException::class, 'sudah direverse');
});
