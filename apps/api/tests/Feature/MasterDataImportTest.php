<?php

use Illuminate\Http\UploadedFile;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\MasterData\Models\Customer;

function importUser(): User
{
    $user = User::factory()->create(['company_id' => 1]);
    $role = Role::create(['company_id' => 1, 'code' => 'imp_'.uniqid(), 'name' => 'Importer']);
    $perm = Permission::firstOrCreate(['code' => 'master.customer.create']);
    $role->permissions()->sync([$perm->id]);
    $user->roles()->sync([$role->id]);

    return $user;
}

test('import CSV: baris valid masuk, baris invalid terlapor per baris', function () {
    $user = importUser();
    $csv = "code,name,currency\nC-100,Buyer Seratus,USD\n,NoCode,USD\nC-101,Buyer Seratus Satu,IDR\n";
    $file = UploadedFile::fake()->createWithContent('customers.csv', $csv);

    $res = $this->actingAs($user)->postJson('/api/master/customers/import', ['file' => $file]);

    $res->assertCreated();
    expect($res->json('total_rows'))->toBe(3);
    expect($res->json('success_rows'))->toBe(2);
    expect($res->json('failed_rows'))->toBe(1);

    expect(Customer::where('code', 'C-100')->exists())->toBeTrue();
    expect(Customer::where('code', 'C-101')->exists())->toBeTrue();
    expect(Customer::where('name', 'NoCode')->exists())->toBeFalse();
});

test('import mendeteksi duplikat tanpa membocorkan SQL error', function () {
    $user = importUser();
    Customer::create(['company_id' => 1, 'code' => 'DUP-CSV', 'name' => 'Existing']);
    $file = UploadedFile::fake()->createWithContent('customers.csv', "code,name\nDUP-CSV,Duplicate\n");

    $res = $this->actingAs($user)->postJson('/api/master/customers/import', ['file' => $file]);

    $res->assertCreated();
    expect($res->json('success_rows'))->toBe(0);
    expect($res->json('failed_rows'))->toBe(1);
    expect(json_encode($res->json('errors')))->not->toContain('SQLSTATE');
});

test('import menolak header tidak dikenal', function () {
    $user = importUser();
    $file = UploadedFile::fake()->createWithContent('customers.csv', "code,name,unexpected\nC-1,Buyer,value\n");

    $res = $this->actingAs($user)->postJson('/api/master/customers/import', ['file' => $file]);

    $res->assertCreated()->assertJsonPath('status', 'FAILED');
    expect($res->json('errors.0.message'))->toContain('Kolom tidak dikenal');
});

test('import CSV tanpa permission → 403', function () {
    $user = User::factory()->create(['company_id' => 1]);
    $file = UploadedFile::fake()->createWithContent('c.csv', "code,name\nX,X\n");

    $this->actingAs($user)->postJson('/api/master/customers/import', ['file' => $file])
        ->assertForbidden();
});

test('import menolak file non-CSV (BR-112 upload validation)', function () {
    $user = importUser();
    $file = UploadedFile::fake()->create('evil.php', 10, 'application/x-php');

    $this->actingAs($user)->postJson('/api/master/customers/import', ['file' => $file])
        ->assertUnprocessable();
});
