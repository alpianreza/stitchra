<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Modules\Core\Models\ApprovalRequest;
use Modules\Core\Models\Concerns\BelongsToCompany;

class ValuationAllocationProfile extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id','code','version','effective_date','status','approval_request_id','created_by'];
    protected function casts(): array { return ['effective_date'=>'date']; }
    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            if ($model->getOriginal('status') === 'APPROVED' && $model->isDirty()) throw new LogicException('Approved valuation allocation profile is immutable.');
        });
        static::deleting(fn () => throw new LogicException('Valuation allocation profile cannot be deleted.'));
    }
    public function rules(): HasMany { return $this->hasMany(ValuationAllocationRule::class, 'profile_id'); }
    public function approvalRequest(): BelongsTo { return $this->belongsTo(ApprovalRequest::class); }
}
