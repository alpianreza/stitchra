<?php

use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\MasterData\Models\Currency;
use Modules\MasterData\Models\Customer;
use Modules\MasterData\Models\Material;

function masterUser(array $permissions): User
{
    $user = User::factory()->create(['company_id' => 1]);
    $role = Role::create(['company_id' => 1, 'code' => 'md_'.uniqid(), 'name' => 'MD Test']);
    $ids = collect($permissions)->map(fn ($c) => Permission::firstOrCreate(['code' => $c])->id);
    $role->permissions()->sync($ids);
    $user->roles()->sync([$role->id]);

    return $user;
}

test('CRUD customer lengkap dengan permission yang benar', function () {
    $user = masterUser(['master.customer.view', 'master.customer.create', 'master.customer.update', 'master.customer.delete']);

    $res = $this->actingAs($user)->postJson('/api/master/customers', [
        'code' => 'CUST-001', 'name' => 'Buyer A', 'currency' => 'USD',
    ])->assertCreated();
    $id = $res->json('id');

    $this->actingAs($user)->getJson("/api/master/customers/{$id}")->assertOk()->assertJsonPath('code', 'CUST-001');
    $this->actingAs($user)->putJson("/api/master/customers/{$id}", ['name' => 'Buyer A Intl'])
        ->assertOk()->assertJsonPath('name', 'Buyer A Intl');
    $this->actingAs($user)->deleteJson("/api/master/customers/{$id}")->assertOk();
    expect(Customer::withTrashed()->find($id)->deleted_at)->not->toBeNull();
});

test('search generik hanya memakai kolom yang tersedia', function () {
    $user = masterUser(['master.customer.view']);
    Customer::create(['company_id' => 1, 'code' => 'SEARCH-1', 'name' => 'Buyer Searchable']);

    $this->actingAs($user)->getJson('/api/master/customers?q=Searchable')
        ->assertOk()->assertJsonPath('data.0.code', 'SEARCH-1');
});

test('filter pagination invalid ditolak', function () {
    $user = masterUser(['master.customer.view']);
    $this->actingAs($user)->getJson('/api/master/customers?per_page=0')->assertUnprocessable();
});

test('tanpa permission → 403 (BR-110 server-side)', function () {
    $user = masterUser(['master.customer.view']);
    $this->actingAs($user)->postJson('/api/master/customers', ['code' => 'X', 'name' => 'X'])->assertForbidden();
});

test('kode duplikat per company ditolak', function () {
    $user = masterUser(['master.customer.create']);
    Customer::create(['company_id' => 1, 'code' => 'DUP', 'name' => 'Satu']);
    $this->actingAs($user)->postJson('/api/master/customers', ['code' => 'DUP', 'name' => 'Dua'])->assertUnprocessable();
});

test('composite unique exchange rate ditolak sebagai validation error', function () {
    $user = masterUser(['master.finance.create']);
    $currency = Currency::create(['company_id' => 1, 'code' => 'TST', 'name' => 'Test Currency']);
    $payload = ['currency_id' => $currency->id, 'rate_date' => '2026-08-31', 'rate' => 15000];

    $this->actingAs($user)->postJson('/api/master/exchange-rates', $payload)->assertCreated();
    $this->actingAs($user)->postJson('/api/master/exchange-rates', $payload)->assertUnprocessable();
});

test('referensi master lintas company ditolak saat validasi', function () {
    $user = masterUser(['master.style.create']);
    $otherCompanyCustomer = Customer::withoutGlobalScopes()->create([
        'company_id' => 2, 'code' => 'OTHER-'.uniqid(), 'name' => 'Company B',
    ]);

    $this->actingAs($user)->postJson('/api/master/styles', [
        'style_no' => 'STYLE-X', 'customer_id' => $otherCompanyCustomer->id, 'category' => 'WOVEN',
    ])->assertUnprocessable();
});

test('BR-002: konversi kg ke meter dari GSM & lebar benar', function () {
    $material = new Material(['gsm' => 180, 'width_cm' => 150]);
    expect($material->kgToMeter(90))->toBeGreaterThan(333.33)->toBeLessThan(333.34);
    expect((new Material())->kgToMeter(90))->toBeNull();
});

test('validasi master: supplier type harus terkontrol', function () {
    $user = masterUser(['master.supplier.create']);
    $this->actingAs($user)->postJson('/api/master/suppliers', ['code' => 'S1', 'name' => 'S', 'type' => 'NGASAL'])->assertUnprocessable();
    $this->actingAs($user)->postJson('/api/master/suppliers', ['code' => 'S1', 'name' => 'S', 'type' => 'FABRIC'])->assertCreated();
});

test('BR-011: company B tidak melihat data company A', function () {
    $userA = masterUser(['master.customer.view']);
    Customer::create(['company_id' => 1, 'code' => 'A-ONLY', 'name' => 'Milik A']);

    $userB = User::factory()->create(['company_id' => 2]);
    $roleB = Role::create(['company_id' => 2, 'code' => 'md_b', 'name' => 'B']);
    $perm = Permission::firstOrCreate(['code' => 'master.customer.view']);
    $roleB->permissions()->sync([$perm->id]);
    $userB->roles()->sync([$roleB->id]);

    $this->actingAs($userB)->getJson('/api/master/customers', ['X-Company-Id' => 1])->assertForbidden();
});
