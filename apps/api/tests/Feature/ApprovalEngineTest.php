<?php

use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\ApprovalFlow;
use Modules\Core\Models\ApprovalFlowStep;
use Modules\Core\Models\ApprovalRequest;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\MasterData\Models\Customer;
use Modules\Sales\Services\SalesOrderService;

/** Helper: user dengan role tertentu untuk approval */
function approver(string $roleCode): User
{
    $user = User::factory()->create(['company_id' => 1]);
    $role = Role::withoutGlobalScopes()->firstOrCreate(
        ['company_id' => 1, 'code' => $roleCode],
        ['name' => $roleCode],
    );
    $user->roles()->sync([$role->id]);

    return $user;
}

/** Helper: flow approval 2 step untuk doc_type */
function makeFlow(string $docType, string $role1, string $role2): ApprovalFlow
{
    $r1 = Role::withoutGlobalScopes()->firstOrCreate(['company_id' => 1, 'code' => $role1], ['name' => $role1]);
    $r2 = Role::withoutGlobalScopes()->firstOrCreate(['company_id' => 1, 'code' => $role2], ['name' => $role2]);

    $flow = ApprovalFlow::create(['company_id' => 1, 'doc_type' => $docType, 'version' => 1, 'mode' => 'sequential', 'is_active' => true]);
    ApprovalFlowStep::create(['flow_id' => $flow->id, 'step_no' => 1, 'role_id' => $r1->id]);
    ApprovalFlowStep::create(['flow_id' => $flow->id, 'step_no' => 2, 'role_id' => $r2->id]);

    return $flow;
}

test('BR-015: approval sequential menolak request stale dan menyelesaikan dua step', function () {
    makeFlow('SO', 'sales_mgr', 'management');

    $customer = Customer::create(['company_id' => 1, 'code' => 'C-'.uniqid(), 'name' => 'Buyer']);
    $style = \Modules\MasterData\Models\Style::create(['company_id' => 1, 'style_no' => 'ST-'.uniqid(), 'category' => 'WOVEN']);
    $color = \Modules\MasterData\Models\Color::create(['company_id' => 1, 'code' => 'BLK', 'name' => 'Black']);
    $colorway = \Modules\MasterData\Models\Colorway::create(['company_id' => 1, 'style_id' => $style->id, 'color_id' => $color->id]);
    $size = \Modules\MasterData\Models\Size::create(['company_id' => 1, 'code' => 'M']);

    $creator = approver('sales_'.uniqid());
    $so = app(SalesOrderService::class)->create(
        companyId: 1,
        header: ['customer_id' => $customer->id, 'order_date' => now()->toDateString()],
        lines: [['style_id' => $style->id, 'colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty' => 1000, 'price' => 5.5]],
        creator: $creator,
    );

    app(SalesOrderService::class)->submit($so, $creator);

    $request = ApprovalRequest::withoutGlobalScopes()->where('doc_type', 'SO')->where('doc_id', $so->id)->first();
    expect($request->status)->toBe('PENDING');
    expect($request->current_step)->toBe(1);

    $engine = app(ApprovalEngine::class);
    $salesManager = approver('sales_mgr');
    $management = approver('management');

    $engine->approve($request, $salesManager);
    expect($request->fresh()->current_step)->toBe(2);
    expect($request->fresh()->status)->toBe('PENDING');

    // Object request lama masih menunjuk step 1 dan tidak boleh melompati step 2.
    expect(fn () => $engine->approve($request, $management))
        ->toThrow(RuntimeException::class, 'Step approval sudah berubah');

    $engine->approve($request->fresh(), $management);
    expect($request->fresh()->status)->toBe('APPROVED');
    expect($so->fresh()->status)->toBe('APPROVED');
});

test('BR-015: approver salah role ditolak', function () {
    makeFlow('SO', 'sales_mgr', 'management');

    $customer = Customer::create(['company_id' => 1, 'code' => 'C-'.uniqid(), 'name' => 'B']);
    $style = \Modules\MasterData\Models\Style::create(['company_id' => 1, 'style_no' => 'ST-'.uniqid(), 'category' => 'KNIT']);
    $color = \Modules\MasterData\Models\Color::create(['company_id' => 1, 'code' => 'WHT', 'name' => 'White']);
    $colorway = \Modules\MasterData\Models\Colorway::create(['company_id' => 1, 'style_id' => $style->id, 'color_id' => $color->id]);
    $size = \Modules\MasterData\Models\Size::create(['company_id' => 1, 'code' => 'L']);

    $creator = approver('sales_'.uniqid());
    $so = app(SalesOrderService::class)->create(1, ['customer_id' => $customer->id, 'order_date' => now()->toDateString()],
        [['style_id' => $style->id, 'colorway_id' => $colorway->id, 'size_id' => $size->id, 'qty' => 500, 'price' => 4]], $creator);
    app(SalesOrderService::class)->submit($so, $creator);

    $request = ApprovalRequest::withoutGlobalScopes()->where('doc_type', 'SO')->where('doc_id', $so->id)->first();

    app(ApprovalEngine::class)->approve($request, approver('random_role'));
})->throws(RuntimeException::class);

test('SO tanpa lines ditolak', function () {
    $customer = Customer::create(['company_id' => 1, 'code' => 'C-'.uniqid(), 'name' => 'B']);
    $creator = approver('sales_'.uniqid());

    app(SalesOrderService::class)->create(1, ['customer_id' => $customer->id, 'order_date' => now()->toDateString()], [], $creator);
})->throws(RuntimeException::class);
