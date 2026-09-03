<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Core\Models\Concerns\BelongsToCompany;

class ValuationAllocationRule extends Model
{
    use BelongsToCompany;
    public $timestamps = false;
    protected $fillable = ['company_id','profile_id','component','stage','allocation_rule','allocation_value','allocation_mode','source_structure','created_by','created_at'];
    protected function casts(): array { return ['allocation_value'=>'decimal:8','source_structure'=>'array','created_at'=>'datetime']; }
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Valuation allocation rule is immutable.'));
        static::deleting(fn () => throw new LogicException('Valuation allocation rule cannot be deleted.'));
    }
    public function profile(): BelongsTo { return $this->belongsTo(ValuationAllocationProfile::class, 'profile_id'); }
}
