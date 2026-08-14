<?php

use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;

/**
 * BR-110: permission dicek server-side. User tanpa permission → 403.
 */
function makeUserWithPermission(?string $permission): User
{
    $user = User::factory()->create(['company_id' => 1]);

    $role = Role::create(['company_id' => 1, 'code' => 'test_role_'.uniqid(), 'name' => 'Test']);

    if ($permission !== null) {
        $perm = Permission::firstOrCreate(['code' => $permission]);
        $role->permissions()->sync([$perm->id]);
    }

    $user->roles()->sync([$role->id]);

    return $user;
}

test('user dengan permission lolos middleware', function () {
    $user = makeUserWithPermission('sales.order.view');

    expect($user->fresh()->hasPermission('sales.order.view'))->toBeTrue();
});

test('user tanpa permission ditolak', function () {
    $user = makeUserWithPermission(null);

    expect($user->fresh()->hasPermission('sales.order.create'))->toBeFalse();
});

test('endpoint terproteksi mengembalikan 403 tanpa permission', function () {
    Route::middleware(['auth:sanctum', 'company', 'permission:sales.order.create'])
        ->get('/_test/protected', fn () => response()->json(['ok' => true]));

    $user = makeUserWithPermission(null);

    $this->actingAs($user)
        ->getJson('/api/_test/protected')
        ->assertForbidden();

    $user2 = makeUserWithPermission('sales.order.create');

    $this->actingAs($user2)
        ->getJson('/api/_test/protected')
        ->assertOk();
});

test('BR-011: user tidak bisa mengakses company lain via header', function () {
    $user = makeUserWithPermission('sales.order.view');

    Route::middleware(['auth:sanctum', 'company'])
        ->get('/_test/company', fn () => response()->json(['ok' => true]));

    $this->actingAs($user)
        ->getJson('/api/_test/company', ['X-Company-Id' => 999])
        ->assertForbidden();
});
