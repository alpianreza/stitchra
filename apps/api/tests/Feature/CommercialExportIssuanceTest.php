<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\MasterData\Models\Color;
use Modules\MasterData\Models\Colorway;
use Modules\MasterData\Models\Currency;
use Modules\MasterData\Models\Customer;
use Modules\MasterData\Models\Size;
use Modules\MasterData\Models\Style;
use Modules\Sales\Models\SalesOrder;
use Modules\Shipping\Models\Shipment;
use Modules\Shipping\Services\ExportDocumentService;

/** Isolated document fixture: no QC, inventory, valuation or GL workflow is exercised. */
function commercialExportIssueFixture(string $kind): array
{
    $user = User::factory()->create(['company_id' => 1]);
    $currency = Currency::firstOrCreate(['company_id' => 1, 'code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
    $buyer = Customer::create(['company_id' => 1, 'code' => 'CE-'.uniqid(), 'name' => 'Export buyer']);
    $style = Style::create(['company_id' => 1, 'style_no' => 'CE-'.uniqid(), 'category' => 'WOVEN']);
    $color = Color::create(['company_id' => 1, 'code' => 'CE-BLK', 'name' => 'Black']);
    $colorway = Colorway::create(['company_id' => 1, 'style_id' => $style->id, 'color_id' => $color->id]);
    $size = Size::create(['company_id' => 1, 'code' => 'CE-M']);
    $so = SalesOrder::create([
        'company_id' => 1, 'doc_no' => 'SO-CE-'.uniqid(), 'customer_id' => $buyer->id,
        'currency_id' => $currency->id, 'exchange_rate' => 1,
        'order_date' => now()->toDateString(), 'status' => 'CONFIRMED', 'created_by' => $user->id,
    ]);
    $matrix = ['style_id' => $style->id, 'colorway_id' => $colorway->id, 'size_id' => $size->id];
    $so->lines()->create($matrix + ['qty' => 10, 'price' => 15]);
    $shipment = Shipment::create([
        'company_id' => 1, 'doc_no' => 'SHP-CE-'.uniqid(), 'sales_order_id' => $so->id,
        'ship_date' => now()->toDateString(), 'status' => 'DRAFT', 'created_by' => $user->id,
    ]);
    $shipment->lines()->create($matrix + ['qty_shipped' => 10]);
    $service = app(ExportDocumentService::class);
    $document = $kind === 'invoice'
        ? $service->createInvoice($shipment, ['invoice_date' => now()->toDateString()], $user)
        : $service->addDocument($shipment, ['document_type' => 'COO', 'reference_no' => 'COO-CE-1'], $user);

    return [$user, $shipment, $document, $service];
}

function commercialExportIssue($service, string $kind, $document, User $user)
{
    return $kind === 'invoice'
        ? $service->issueInvoice($document, $user)
        : $service->issueDocument($document, $user);
}

test('commercial/export draft cannot be issued after its shipment is cancelled', function (string $kind) {
    [$user, $shipment, $document, $service] = commercialExportIssueFixture($kind);
    // Retain stale in-memory models to ensure the service reads the persisted status.
    DB::table('shipments')->where('id', $shipment->id)->update(['status' => 'CANCELLED']);
    $before = $document->fresh()->getRawOriginal();
    $auditCount = DB::table('audit_logs')->count();

    expect(fn () => commercialExportIssue($service, $kind, $document, $user))
        ->toThrow(RuntimeException::class, 'Shipment CANCELLED');
    expect($document->fresh()->getRawOriginal())->toBe($before)
        ->and(DB::table('audit_logs')->count())->toBe($auditCount);
})->with(['invoice', 'document']);

test('commercial/export issuance on active shipment is idempotent and audited once', function (string $kind) {
    [$user, , $document, $service] = commercialExportIssueFixture($kind);
    $auditCount = DB::table('audit_logs')->count();
    $issued = commercialExportIssue($service, $kind, $document, $user);
    $before = $issued->fresh()->getRawOriginal();
    $replayed = commercialExportIssue($service, $kind, $document, $user);

    expect($issued->status)->toBe('ISSUED')
        ->and((int) $issued->updated_by)->toBe($user->id)
        ->and($replayed->id)->toBe($issued->id)
        ->and($replayed->fresh()->getRawOriginal())->toBe($before)
        ->and(DB::table('audit_logs')->count())->toBe($auditCount + 1);
})->with(['invoice', 'document']);

test('retry of already issued commercial/export document preserves history after cancellation', function (string $kind) {
    [$user, $shipment, $document, $service] = commercialExportIssueFixture($kind);
    $issued = commercialExportIssue($service, $kind, $document, $user);
    DB::table('shipments')->where('id', $shipment->id)->update(['status' => 'CANCELLED']);
    $before = $issued->fresh()->getRawOriginal();
    $auditCount = DB::table('audit_logs')->count();

    $replayed = commercialExportIssue($service, $kind, $document, $user);
    expect($replayed->id)->toBe($issued->id)
        ->and($replayed->fresh()->getRawOriginal())->toBe($before)
        ->and(DB::table('audit_logs')->count())->toBe($auditCount);
})->with(['invoice', 'document']);

test('commercial/export issue checks persisted company rather than caller attributes', function (string $kind) {
    [, , $document, $service] = commercialExportIssueFixture($kind);
    $otherCompany = DB::table('companies')->insertGetId([
        'code' => 'CE-OTHER', 'name' => 'Other', 'base_currency' => 'USD',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $other = User::factory()->create(['company_id' => $otherCompany]);
    $document->company_id = $otherCompany;
    $before = $document->fresh()->getRawOriginal();
    $auditCount = DB::table('audit_logs')->count();

    expect(fn () => commercialExportIssue($service, $kind, $document, $other))
        ->toThrow(RuntimeException::class, 'akses');
    expect($document->fresh()->getRawOriginal())->toBe($before)
        ->and(DB::table('audit_logs')->count())->toBe($auditCount);
})->with(['invoice', 'document']);

test('commercial/export issue rejects a parent shipment from a different company', function (string $kind) {
    [, , $document, $service] = commercialExportIssueFixture($kind);
    $otherCompany = DB::table('companies')->insertGetId([
        'code' => 'CE-OTHER', 'name' => 'Other', 'base_currency' => 'USD',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $other = User::factory()->create(['company_id' => $otherCompany]);
    // Simulate inconsistent legacy data; company and shipment have separate FKs.
    DB::table($document->getTable())->where('id', $document->id)->update(['company_id' => $otherCompany]);
    $before = $document->fresh()->getRawOriginal();
    $auditCount = DB::table('audit_logs')->count();

    expect(fn () => commercialExportIssue($service, $kind, $document, $other))
        ->toThrow(ModelNotFoundException::class);
    expect($document->fresh()->getRawOriginal())->toBe($before)
        ->and(DB::table('audit_logs')->count())->toBe($auditCount);
})->with(['invoice', 'document']);

test('commercial/export completeness checks still reject incomplete drafts', function (string $kind) {
    [$user, , $document, $service] = commercialExportIssueFixture($kind);
    if ($kind === 'invoice') {
        $document->lines()->delete();
    } else {
        $document->update(['reference_no' => null, 'file_reference' => null]);
    }
    $before = $document->fresh()->getRawOriginal();
    $auditCount = DB::table('audit_logs')->count();

    expect(fn () => commercialExportIssue($service, $kind, $document, $user))
        ->toThrow(RuntimeException::class, $kind === 'invoice' ? 'DRAFT lengkap' : 'reference number');
    expect($document->fresh()->getRawOriginal())->toBe($before)
        ->and(DB::table('audit_logs')->count())->toBe($auditCount);
})->with(['invoice', 'document']);
