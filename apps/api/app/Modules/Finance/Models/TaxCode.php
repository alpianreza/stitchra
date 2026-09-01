<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;use Modules\Core\Models\Concerns\BelongsToCompany;
class TaxCode extends Model{use BelongsToCompany;public const KINDS=['OUTPUT_TAX','INPUT_TAX','WITHHOLDING_RECEIVABLE','WITHHOLDING_PAYABLE'];protected $fillable=['company_id','code','name','kind','rate_pct','is_active','created_by','updated_by'];protected function casts():array{return['rate_pct'=>'decimal:6','is_active'=>'boolean'];}}
