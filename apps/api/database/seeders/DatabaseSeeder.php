<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Company;
use Modules\Core\Models\DocNumberingConfig;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Company default (single-company dulu; multi siap via schema)
        $company = Company::firstOrCreate(
            ['code' => 'DEFAULT'],
            ['name' => 'Default Company', 'base_currency' => 'IDR', 'timezone' => 'Asia/Jakarta', 'locale' => 'id']
        );

        // RBAC: permissions + 16 role (dari Roles & Permissions blueprint)
        $this->call(RbacSeeder::class);

        // Super admin awal — GANTI PASSWORD SETELAH LOGIN PERTAMA (BR-111)
        $admin = User::firstOrCreate(
            ['email' => 'admin@stitchra.local'],
            ['company_id' => $company->id, 'name' => 'Administrator', 'password' => 'ChangeMe!123', 'is_active' => true]
        );
        $superAdmin = Role::where('code', 'super_admin')->first();
        if ($superAdmin) {
            $admin->roles()->syncWithoutDetaching([$superAdmin->id]);
        }

        // BR-010: numbering per doc type — PREFIX-YYYY-NNNNNN, counter per tahun
        // Daftar lengkap prefix yang dipakai seluruh modul
        $prefixes = [
            'SO',   // Sales Order
            'PR',   // Purchase Request
            'PO',   // Purchase Order
            'RFQ',  // Request for Quotation
            'GR',   // Goods Receipt
            'FQC',  // Inward Inspection (Fabric/QC)
            'MO',   // Manufacturing Order
            'MI',   // Material Issue (+ fabric return)
            'CUT',  // Cut Order
            'TRF',  // Stock Transfer
            'WIP',  // Transfer WIP (legacy mapping)
            'QC',   // QC Inspection
            'PL',   // Packing List
            'SHP',  // Shipment
            'JW',   // Subcon Order (Jasa Kerja)
            'OUT',  // Production Receipt (FG)
            'ADJ',  // Stock Adjustment (+ OPENING movement)
            'OPN',  // Stock Opname
            'INV',  // Invoice (AR & supplier)
            'PAY',  // Payment (AR/AP)
            'JE',   // Journal Entry
            'SMPL', // Sample
            'COST', // Cost Sheet
        ];

        foreach ($prefixes as $prefix) {
            DocNumberingConfig::firstOrCreate(
                ['company_id' => $company->id, 'prefix' => $prefix],
                ['doc_type' => $prefix, 'padding' => 6, 'reset_period' => 'YEARLY']
            );
        }
    }
}
