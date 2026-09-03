<?php

use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\ApprovalFlow;
use Modules\Core\Models\Role;
use Modules\Packing\Models\PackingList;
use Modules\Packing\Services\PackingService;
use Modules\Qc\Services\QcService;

function d05LegacyPackingList($so, $mo, $user): PackingList
{
    return PackingList::create([
        'company_id' => $so->company_id,
        'doc_no' => 'PL-LEGACY-'.uniqid(),
        'sales_order_id' => $so->id,
        'production_order_id' => $mo->id,
        'qc_inspection_id' => null,
        'status' => 'DRAFT',
        'created_by' => $user->id,
    ]);
}

function d05ApprovalFlow($user): void
{
    $role = Role::withoutGlobalScopes()->create([
        'company_id' => $user->company_id,
        'code' => 'd05-approver-'.uniqid(),
        'name' => 'D-05 Source Approver',
    ]);
    $user->roles()->syncWithoutDetaching([$role->id]);
    $flow = ApprovalFlow::withoutGlobalScopes()->create([
        'company_id' => $user->company_id,
        'doc_type' => PackingService::LEGACY_SOURCE_APPROVAL_DOC_TYPE,
        'version' => 1,
        'mode' => 'sequential',
        'is_active' => true,
        'created_by' => $user->id,
    ]);
    $flow->steps()->create(['step_no' => 1, 'role_id' => $role->id]);
}

test('D-05 source-less legacy Packing List tetap readable dan seluruh mutation fail closed tanpa auto-attachment', function () {
    [$user, , $style, $so, $mo, $colorway, $size, $fgWarehouse] = packFixture();
    $qc = app(QcService::class);
    $pass = $qc->finalize($qc->create($mo, 'FINAL', 100, $user), $user);
    $legacy = d05LegacyPackingList($so, $mo, $user);
    $service = app(PackingService::class);

    $lineage = $service->lineage($legacy, $user);
    expect($lineage['packing_input']['status'])->toBe('MISSING_LEGACY_SOURCE')
        ->and($lineage['packing_input']['read_only'])->toBeTrue()
        ->and($lineage['packing_input']['automatic_attachment'])->toBeFalse()
        ->and($lineage['fg_boundary']['status'])->toBe('BLOCKED_MISSING_LEGACY_SOURCE')
        ->and($lineage['shipment_boundary']['status'])->toBe('BLOCKED_MISSING_LEGACY_SOURCE');

    expect(fn () => $service->addCarton($legacy, [], [[
        'style_id' => $style->id,
        'colorway_id' => $colorway->id,
        'size_id' => $size->id,
        'qty' => 10,
    ]], $user))->toThrow(RuntimeException::class, 'BR-068');

    expect($legacy->fresh()->qc_inspection_id)->toBeNull()
        ->and($legacy->cartons()->count())->toBe(0)
        ->and(DB::table('stock_movements')->where('source_document_type', 'packing_lists')->where('source_document_id', $legacy->id)->exists())->toBeFalse()
        ->and($pass->verdict)->toBe('PASS');

    expect(fn () => $service->finalize($legacy->fresh(), $fgWarehouse->id, $user))->toThrow(RuntimeException::class);
});

test('D-05 source hanya diterapkan setelah explicit reason dan ApprovalEngine approval, dengan retry idempotent', function () {
    [$user, , $style, $so, $mo, $colorway, $size] = packFixture();
    $qc = app(QcService::class);
    $pass = $qc->finalize($qc->create($mo, 'FINAL', 100, $user), $user);
    $legacy = d05LegacyPackingList($so, $mo, $user);
    d05ApprovalFlow($user);
    $service = app(PackingService::class);
    $reason = 'Dokumen QC FINAL dan identitas carton legacy telah diverifikasi.';

    $proposal = $service->requestLegacySourceAttachment($legacy, $pass, $reason, $user);
    $retry = $service->requestLegacySourceAttachment($legacy->fresh(), $pass, $reason, $user);
    expect($retry->id)->toBe($proposal->id)
        ->and($legacy->fresh()->qc_inspection_id)->toBeNull()
        ->and($proposal->approvalRequest->status)->toBe('PENDING');

    expect(fn () => $service->applyLegacySourceAttachment($proposal, $user))
        ->toThrow(RuntimeException::class, 'belum disetujui ApprovalEngine');

    app(ApprovalEngine::class)->approve($proposal->approvalRequest, $user, 'Evidence D-05 approved.');
    $applied = $service->applyLegacySourceAttachment($proposal->fresh(), $user);
    $appliedAgain = $service->applyLegacySourceAttachment($proposal->fresh(), $user);

    expect((int) $applied->qc_inspection_id)->toBe((int) $pass->id)
        ->and((int) $appliedAgain->qc_inspection_id)->toBe((int) $pass->id)
        ->and($proposal->fresh()->applied_at)->not->toBeNull()
        ->and($proposal->fresh()->reason)->toBe($reason);

    $carton = $service->addCarton($applied->fresh(), [], [[
        'style_id' => $style->id,
        'colorway_id' => $colorway->id,
        'size_id' => $size->id,
        'qty' => 10,
    ]], $user);
    expect((float) $carton->lines->sum('qty'))->toBe(10.0);
});

test('D-05 attachment menolak source lintas SO atau MO dan latest FINAL yang bukan PASS', function () {
    [$user, , , $so, $mo] = packFixture();
    $qc = app(QcService::class);
    $pass = $qc->finalize($qc->create($mo, 'FINAL', 100, $user), $user);
    $legacy = d05LegacyPackingList($so, $mo, $user);
    d05ApprovalFlow($user);
    $service = app(PackingService::class);

    [, , , , $otherMo] = packFixture();
    $otherPass = $qc->finalize($qc->create($otherMo, 'FINAL', 100, $user), $user);
    expect(fn () => $service->requestLegacySourceAttachment($legacy, $otherPass, 'Wrong MO evidence.', $user))
        ->toThrow(RuntimeException::class, 'exact same company/SO/MO');

    $newer = $pass->replicate();
    $newer->doc_no = 'QC-REWORK-'.uniqid();
    $newer->cycle = (int) $pass->cycle + 1;
    $newer->verdict = 'REWORK';
    $newer->save();
    expect(fn () => $service->requestLegacySourceAttachment($legacy->fresh(), $pass, 'Stale PASS evidence.', $user))
        ->toThrow(RuntimeException::class, 'cycle QC FINAL terbaru');
});

test('D-05 attachment menolak quantity dan downstream conflict tanpa rewrite histori', function () {
    [$user, , $style, $so, $mo, $colorway, $size] = packFixture();
    $qc = app(QcService::class);
    $pass = $qc->finalize($qc->create($mo, 'FINAL', 10, $user), $user);
    d05ApprovalFlow($user);
    $service = app(PackingService::class);

    $quantityConflict = d05LegacyPackingList($so, $mo, $user);
    $carton = $quantityConflict->cartons()->create([
        'company_id' => $quantityConflict->company_id,
        'carton_no' => $quantityConflict->doc_no.'-0001',
        'seq' => 1,
    ]);
    $carton->lines()->create([
        'style_id' => $style->id,
        'colorway_id' => $colorway->id,
        'size_id' => $size->id,
        'qty' => 11,
    ]);
    expect(fn () => $service->requestLegacySourceAttachment($quantityConflict, $pass, 'Quantity evidence review.', $user))
        ->toThrow(RuntimeException::class, 'quantity Carton melebihi');
    expect((float) $carton->lines()->sum('qty'))->toBe(11.0);

    $downstreamConflict = d05LegacyPackingList($so, $mo, $user);
    $downstreamConflict->update(['status' => 'APPROVED']);
    expect(fn () => $service->requestLegacySourceAttachment($downstreamConflict->fresh(), $pass, 'Downstream evidence review.', $user))
        ->toThrow(RuntimeException::class, 'downstream status/ITS/Shipment conflict');
    expect($downstreamConflict->fresh()->qc_inspection_id)->toBeNull();
});

test('D-05 static contract keeps API UI approval guards and migration free of historical backfill', function () {
    $service = file_get_contents(app_path('Modules/Packing/Services/PackingService.php'));
    $routes = file_get_contents(app_path('Modules/Qc/routes/qc.php'));
    $ui = file_get_contents(base_path('../web/src/app/(app)/packing/lists/page.tsx'));
    $migration = file_get_contents(database_path('migrations/2026_09_03_000029_add_packing_source_attachments.php'));
    $shipping = file_get_contents(app_path('Modules/Shipping/Services/ShipmentService.php'));

    expect($service)->toContain('MISSING_LEGACY_SOURCE bersifat read-only')
        ->toContain('chronology QC FINAL PASS dan Carton tidak plausible')
        ->toContain('stock_movements')->toContain('stock_ledger')->toContain('shipments')
        ->toContain('ApprovalEngine')
        ->not->toContain('if (! $packingList->qc_inspection_id) { $packingList->update');
    expect($routes)->toContain('legacy-source-candidates')->toContain('source-attachments')->toContain('applySourceAttachment');
    expect($ui)->toContain('MISSING_LEGACY_SOURCE · READ-ONLY')->toContain('Ajukan approval')->toContain('Terapkan source yang disetujui');
    expect(strtoupper($migration))->not->toContain('UPDATE PACKING_LISTS')->not->toContain('INSERT INTO PACKING_LISTS');
    expect($shipping)->toContain('source QC FINAL PASS Packing List tidak valid');
});
