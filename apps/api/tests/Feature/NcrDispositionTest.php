<?php

use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\ApprovalFlow;
use Modules\Core\Models\ApprovalFlowStep;
use Modules\Core\Models\ApprovalRequest;
use Modules\Core\Models\Company;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\MasterData\Models\DefectLibrary;
use Modules\Qc\Models\Ncr;
use Modules\Qc\Models\ReworkOrder;
use Modules\Qc\Services\NcrService;
use Modules\Qc\Services\QcService;

function ncrApprover(string $roleCode): User
{
    $user = User::factory()->create(['company_id' => 1]);
    $role = Role::withoutGlobalScopes()->firstOrCreate(['company_id' => 1, 'code' => $roleCode], ['name' => $roleCode]);
    $user->roles()->sync([$role->id]);
    return $user;
}

function ncrFlow(string $roleCode = 'qc_manager'): void
{
    $role = Role::withoutGlobalScopes()->firstOrCreate(['company_id' => 1, 'code' => $roleCode], ['name' => $roleCode]);
    $flow = ApprovalFlow::create(['company_id' => 1, 'doc_type' => 'NCR', 'version' => 1, 'mode' => 'sequential', 'is_active' => true]);
    ApprovalFlowStep::create(['flow_id' => $flow->id, 'step_no' => 1, 'role_id' => $role->id]);
}

function failedFinalQc(): array
{
    [$user, , , , $mo] = qcFixture();
    $defect = DefectLibrary::create(['company_id' => 1, 'code' => 'NCR-'.uniqid(), 'name' => 'Critical NCR test', 'category' => 'WORKMANSHIP', 'severity' => 'CRITICAL']);
    $qc = app(QcService::class);
    $inspection = $qc->create($mo, 'FINAL', 1200, $user);
    $qc->recordDefects($inspection, [['defect_id' => $defect->id, 'qty' => 1]], $user);
    $inspection = $qc->finalize($inspection->fresh(), $user);
    return [$user, $mo, $inspection, $defect];
}

test('QC FAIL otomatis membuat NCR bernomor dengan trace upstream', function () {
    [, $mo, $inspection] = failedFinalQc();
    $ncr = Ncr::withoutGlobalScopes()->where('qc_inspection_id', $inspection->id)->firstOrFail();
    expect($inspection->verdict)->toBe('REWORK')
        ->and($ncr->doc_no)->toStartWith('NCR-')
        ->and($ncr->status)->toBe('DRAFT')
        ->and($ncr->production_order_id)->toBe($mo->id)
        ->and((float) $ncr->qty)->toBe(1200.0);
});

test('approval NCR REWORK membuat rework order dan reinspection PASS menutup chain', function () {
    [$creator, $mo, $inspection] = failedFinalQc();
    $ncr = $inspection->ncr;
    $service = app(NcrService::class);
    $service->addDisposition($ncr, ['action' => 'REWORK', 'qty' => 1200, 'target_stage' => 'SEWING'], $creator);
    ncrFlow();
    $service->submit($ncr->fresh(), $creator);

    $request = ApprovalRequest::withoutGlobalScopes()->where('doc_type', 'NCR')->where('doc_id', $ncr->id)->firstOrFail();
    app(ApprovalEngine::class)->approve($request, ncrApprover('qc_manager'));
    $order = ReworkOrder::withoutGlobalScopes()->where('ncr_id', $ncr->id)->firstOrFail();
    expect($ncr->fresh()->status)->toBe('APPROVED')->and($order->status)->toBe('OPEN')->and($order->target_stage)->toBe('SEWING');

    $reinspection = app(QcService::class)->create($mo->fresh(), 'FINAL', 1200, $creator);
    expect($order->fresh()->reinspection_id)->toBe($reinspection->id);
    app(QcService::class)->finalize($reinspection, $creator);
    expect($order->fresh()->status)->toBe('CLOSED')->and($ncr->fresh()->status)->toBe('CLOSED');
});

test('total disposition tidak dapat melebihi qty NCR', function () {
    [$user, , $inspection] = failedFinalQc();
    $service = app(NcrService::class);
    $service->addDisposition($inspection->ncr, ['action' => 'REJECT', 'qty' => 1000], $user);
    $service->addDisposition($inspection->ncr->fresh(), ['action' => 'SCRAP', 'qty' => 201], $user);
})->throws(RuntimeException::class, 'Total qty disposition');

test('user company lain tidak dapat mengubah NCR', function () {
    [, , $inspection] = failedFinalQc();
    $company = Company::create(['code' => 'OTHER-'.uniqid(), 'name' => 'Other Company', 'base_currency' => 'IDR']);
    $outsider = User::factory()->create(['company_id' => $company->id]);
    app(NcrService::class)->addDisposition($inspection->ncr, ['action' => 'REJECT', 'qty' => 1200], $outsider);
})->throws(RuntimeException::class, 'akses ke company NCR');
