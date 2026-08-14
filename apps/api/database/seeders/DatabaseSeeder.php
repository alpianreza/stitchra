<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Company;
use Modules\Core\Models\User;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Company default (implement awal 1 company — BR-011/OBD-029)
        $company = Company::firstOrCreate(
            ['code' => 'DEFAULT'],
            ['name' => 'Perusahaan Default', 'base_currency' => 'IDR'],
        );

        // Super admin awal — ganti password setelah login pertama!
        $admin = User::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $company->id, 'email' => 'admin@stitchra.local'],
            ['name' => 'Super Admin', 'password' => 'ChangeMe!123'],
        );
        $admin->companies()->syncWithoutDetaching([$company->id]);

        $this->call(RbacSeeder::class);

        // Assign role super_admin ke user admin
        $role = \Modules\Core\Models\Role::withoutGlobalScopes()
            ->where('company_id', $company->id)->where('code', 'super_admin')->first();
        if ($role) {
            $admin->roles()->syncWithoutDetaching([$role->id]);
        }
    }
}
