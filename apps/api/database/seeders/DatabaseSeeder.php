<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;use Modules\Core\Models\Company;use Modules\Core\Models\DocNumberingConfig;use Modules\Core\Models\Role;use Modules\Core\Models\User;
class DatabaseSeeder
{
 public function run():void{$company=Company::firstOrCreate(['id'=>1],['code'=>'DEFAULT','name'=>'Default Company','base_currency'=>env('STITCHRA_BASE_CURRENCY','USD')]);$this->call(RbacSeeder::class);$admin=User::firstOrCreate(['email'=>'admin@stitchra.local'],['company_id'=>$company->id,'name'=>'Administrator','password'=>'ChangeMe!123','is_active'=>true]);$superAdmin=Role::where('code','super_admin')->first();if($superAdmin)$admin->roles()->syncWithoutDetaching([$superAdmin->id]);$prefixes=['SO','PR','PO','RFQ','GR','FQC','MO','MI','CUT','TRF','WIP','QC','NCR','PL','SHP','JW','OUT','ADJ','OPN','INV','CI','PAY','JE','SMPL','COST'];foreach($prefixes as$prefix)DocNumberingConfig::firstOrCreate(['company_id'=>$company->id,'doc_type'=>$prefix],['prefix'=>$prefix,'digits'=>6,'reset_yearly'=>true]);}
}
