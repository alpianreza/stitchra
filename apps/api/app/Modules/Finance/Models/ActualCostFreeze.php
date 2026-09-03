<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Core\Models\ApprovalRequest;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Production\Models\ProductionOrder;

class ActualCostFreeze extends Model
{
    use BelongsToCompany;
    protected $fillable = ['company_id','production_order_id','eligibility_id','freeze_version','status','period','standard_snapshot_hash','denominator_quantity','component_amounts','source_evidence','calculation_hash','approval_request_id','created_by','frozen_by','frozen_at'];
    protected function casts(): array { return ['component_amounts'=>'array','source_evidence'=>'array','denominator_quantity'=>'decimal:4','frozen_at'=>'datetime']; }
    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            if ($model->getOriginal('status') === 'FROZEN' && $model->isDirty()) throw new LogicException('Frozen actual cost is immutable; create a new version.');
        });
        static::deleting(fn () => throw new LogicException('Actual cost freeze cannot be deleted.'));
    }
    public function productionOrder(): BelongsTo { return $this->belongsTo(ProductionOrder::class); }
    public function eligibility(): BelongsTo { return $this->belongsTo(MoValuationEligibility::class, 'eligibility_id'); }
    public function approvalRequest(): BelongsTo { return $this->belongsTo(ApprovalRequest::class); }
}
