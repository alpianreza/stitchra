<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Production\Models\ProductionOrder;

class FgActualCosting extends Model
{
    use BelongsToCompany;
    protected $fillable=['company_id','production_order_id','actual_cost_freeze_id','valuation_object','costing_version','fg_received_quantity','actual_total_cost','actual_cost_per_pcs','standard_cost_per_pcs','provisional_fg_value','component_variance_total','currency','calculation_version','standard_snapshot_hash','source_hash','calculation_hash','source_evidence','completeness','status','created_by','frozen_at'];
    protected function casts(): array { return ['source_evidence'=>'array','completeness'=>'array','frozen_at'=>'datetime']; }
    protected static function booted(): void
    {
        static::updating(function(self $model):void{
            $allowed=['status','frozen_at','updated_at'];
            if($model->getOriginal('status')==='FROZEN'||array_diff(array_keys($model->getDirty()),$allowed))throw new LogicException('FG actual costing versions are immutable.');
        });
        static::deleting(fn()=>throw new LogicException('FG actual costing cannot be deleted.'));
    }
    public function productionOrder(): BelongsTo { return $this->belongsTo(ProductionOrder::class); }
    public function freeze(): BelongsTo { return $this->belongsTo(ActualCostFreeze::class,'actual_cost_freeze_id'); }
    public function components(): HasMany { return $this->hasMany(FgActualCostingComponent::class); }
}
