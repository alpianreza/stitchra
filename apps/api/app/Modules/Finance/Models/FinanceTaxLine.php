<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;use LogicException;use Modules\Core\Models\Concerns\BelongsToCompany;
class FinanceTaxLine extends Model{use BelongsToCompany;protected $fillable=['company_id','document_type','document_id','tax_code_id','tax_code','kind','taxable_base','rate_pct','amount','created_by'];protected function casts():array{return['taxable_base'=>'decimal:4','rate_pct'=>'decimal:6','amount'=>'decimal:4'];}protected static function booted():void{static::updating(fn()=>throw new LogicException('Finance tax line bersifat immutable.'));static::deleting(fn()=>throw new LogicException('Finance tax line bersifat append-only.'));}}
