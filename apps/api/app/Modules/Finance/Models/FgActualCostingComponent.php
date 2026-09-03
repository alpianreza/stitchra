<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Core\Models\Concerns\BelongsToCompany;

class FgActualCostingComponent extends Model
{
    use BelongsToCompany;
    public $timestamps=false;
    protected $fillable=['company_id','fg_actual_costing_id','component','completeness_status','actual_cost','provisional_value','variance_amount','source_evidence','source_hash','created_at'];
    protected function casts(): array { return ['source_evidence'=>'array','created_at'=>'datetime']; }
    protected static function booted(): void
    {
        static::updating(fn()=>throw new LogicException('FG actual costing components are immutable.'));
        static::deleting(fn()=>throw new LogicException('FG actual costing components cannot be deleted.'));
    }
    public function costing(): BelongsTo { return $this->belongsTo(FgActualCosting::class,'fg_actual_costing_id'); }
}
