<?php

namespace Modules\Planning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Sales\Models\OrderAmendment;

/** BR-121: setiap run tersimpan sebagai versi; amendment memakai before/after run untuk delta yang repeatable. */
class MrpRun extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'run_no', 'run_type', 'source_amendment_id',
        'baseline_mrp_run_id', 'params', 'status', 'created_by',
    ];

    protected function casts(): array { return ['params' => 'array']; }
    public function requirements(): HasMany { return $this->hasMany(MrpRequirement::class); }
    public function sourceAmendment(): BelongsTo { return $this->belongsTo(OrderAmendment::class, 'source_amendment_id'); }
    public function baselineRun(): BelongsTo { return $this->belongsTo(self::class, 'baseline_mrp_run_id'); }
}
