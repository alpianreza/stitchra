<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Core\Models\ApprovalRequest;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Production\Models\ProductionOrder;

class MoValuationEligibility extends Model
{
    use BelongsToCompany;
    protected $fillable = ['company_id','production_order_id','allocation_profile_id','policy_version','standard_snapshot_hash','allocation_snapshot','allocation_snapshot_hash','effective_date','status','approval_request_id','created_by','approved_by','approved_at'];
    protected function casts(): array { return ['allocation_snapshot'=>'array','effective_date'=>'date','approved_at'=>'datetime']; }
    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            if ($model->getOriginal('status') === 'APPROVED' && $model->isDirty()) throw new LogicException('Approved MO valuation eligibility is immutable.');
        });
        static::deleting(fn () => throw new LogicException('MO valuation eligibility cannot be deleted.'));
    }
    public function productionOrder(): BelongsTo { return $this->belongsTo(ProductionOrder::class); }
    public function profile(): BelongsTo { return $this->belongsTo(ValuationAllocationProfile::class, 'allocation_profile_id'); }
    public function approvalRequest(): BelongsTo { return $this->belongsTo(ApprovalRequest::class); }
}
