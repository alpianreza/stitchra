<?php

use Modules\Core\Models\Company;
use Modules\Core\Models\User;
use Modules\Packing\Models\PackingList;
use Modules\Packing\Services\PackingService;
use Modules\Qc\Services\QcService;
use Modules\Shipping\Models\Shipment;
use Modules\Shipping\Services\ShipmentService;

function d05BoundaryLegacyPackingList($so, $mo, $user): PackingList
{
    return PackingList::create([
        'company_id' => $so->company_id,
        'doc_no' => 'PL-D05-BOUNDARY-'.uniqid(),
        'sales_order_id' => $so->id,
        'production_order_id' => $mo->id,
        'qc_inspection_id' => null,
        'status' => 'DRAFT',
        'created_by' => $user->id,
    ]);
}

test('D-05 chronology conflict fails closed when a legacy Carton predates selected FINAL PASS', function () {
    [$user, , $style, $so, $mo, $colorway, $size] = packFixture();
    $qc = app(QcService::class);
    $pass = $qc->finalize($qc->create($mo, 'FINAL', 100, $user), $user);
    $legacy = d05BoundaryLegacyPackingList($so, $mo, $user);
    $carton = $legacy->cartons()->create([
        'company_id' => $legacy->company_id,
        'carton_no' => $legacy->doc_no.'-0001',
        'seq' => 1,
    ]);
    $carton->lines()->create([
        'style_id' => $style->id,
        'colorway_id' => $colorway->id,
        'size_id' => $size->id,
        'qty' => 1,
    ]);
    DB::table('cartons')->where('id', $carton->id)->update([
        'created_at' => $pass->updated_at->copy()->subMinute(),
    ]);

    expect(fn () => app(PackingService::class)->requestLegacySourceAttachment(
        $legacy,
        $pass,
        'Chronology evidence review.',
        $user,
    ))->toThrow(RuntimeException::class, 'chronology QC FINAL PASS dan Carton tidak plausible');

    expect($legacy->fresh()->qc_inspection_id)->toBeNull()
        ->and((float) $carton->lines()->sum('qty'))->toBe(1.0);
});

test('D-05 tenant isolation blocks source review by a user outside the Packing List company', function () {
    [$user, , , $so, $mo] = packFixture();
    $qc = app(QcService::class);
    $qc->finalize($qc->create($mo, 'FINAL', 100, $user), $user);
    $legacy = d05BoundaryLegacyPackingList($so, $mo, $user);
    $otherCompany = Company::create([
        'code' => 'D05-'.uniqid(),
        'name' => 'D-05 Other Company',
        'base_currency' => 'IDR',
    ]);
    $outsider = User::factory()->create(['company_id' => $otherCompany->id]);

    expect(fn () => app(PackingService::class)->legacySourceCandidates($legacy, $outsider))
        ->toThrow(RuntimeException::class, 'tidak memiliki akses ke company packing');
    expect($legacy->fresh()->qc_inspection_id)->toBeNull();
});

test('D-05 source-less historical Packing List cannot create Shipment or downstream rows', function () {
    [$user, , , $so, $mo] = packFixture();
    $legacy = d05BoundaryLegacyPackingList($so, $mo, $user);
    $legacy->update(['status' => 'APPROVED']);

    expect(fn () => app(ShipmentService::class)->create(
        $legacy->fresh(),
        ['ship_date' => now()->toDateString()],
        $user,
    ))->toThrow(RuntimeException::class, 'source QC FINAL PASS Packing List tidak valid');

    expect(Shipment::withoutGlobalScopes()->where('packing_list_id', $legacy->id)->exists())->toBeFalse();
});
