<?php

namespace Modules\Finance\Models;
use Illuminate\Database\Eloquent\Model;use LogicException;
class FxRevaluationLine extends Model{protected $fillable=['fx_revaluation_run_id','side','document_id','currency_id','foreign_outstanding','carrying_rate','closing_rate','carrying_base','revalued_base','gain_loss'];protected function casts():array{return['foreign_outstanding'=>'decimal:4','carrying_rate'=>'decimal:6','closing_rate'=>'decimal:6','carrying_base'=>'decimal:4','revalued_base'=>'decimal:4','gain_loss'=>'decimal:4'];}protected static function booted():void{static::updating(fn()=>throw new LogicException('FX revaluation line bersifat immutable.'));static::deleting(fn()=>throw new LogicException('FX revaluation line bersifat append-only.'));}}
