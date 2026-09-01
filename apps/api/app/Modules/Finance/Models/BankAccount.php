<?php
namespace Modules\Finance\Models;use Illuminate\Database\Eloquent\Model;use Modules\Core\Models\Concerns\BelongsToCompany;class BankAccount extends Model{use BelongsToCompany;protected $fillable=['company_id','code','name','bank_name','currency_id','coa_id','is_active','created_by'];protected function casts():array{return['is_active'=>'boolean'];}}
