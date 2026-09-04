<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Planning\Models\MrpRun;

/** BR-022: amendment terkunci setelah cutting mulai; applied amendment menyimpan baseline dan delta MRP. */
class OrderAmendment extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['DRAFT','SUBMITTED','APPROVED','REJECTED','CANCELLED'];

    protected $fillable = [
        'company_id', 'doc_no', 'sales_order_id', 'line_delta', 'reason',
        'old_ex_factory_date', 'new_ex_factory_date', 'status',
        'baseline_mrp_run_id', 'delta_mrp_run_id', 'applied_at', 'applied_by',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'line_delta' => 'array', 'old_ex_factory_date' => 'date',
            'new_ex_factory_date' => 'date', 'applied_at' => 'datetime',
        ];
    }

    public function salesOrder(): BelongsTo { return $this->belongsTo(SalesOrder::class); }
    public function lines(): HasMany { return $this->hasMany(OrderAmendmentLine::class); }
    public function baselineMrpRun(): BelongsTo { return $this->belongsTo(MrpRun::class, 'baseline_mrp_run_id'); }
    public function deltaMrpRun(): BelongsTo { return $this->belongsTo(MrpRun::class, 'delta_mrp_run_id'); }
}
