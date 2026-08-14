<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;

/** BR-041: issue material ke MO — ACTUAL (fabric per roll) / BACKFLUSH (trim otomatis) */
class MaterialIssue extends Model
{
    use BelongsToCompany;

    public const MODES = ['ACTUAL', 'BACKFLUSH'];

    protected $fillable = [
        'company_id', 'doc_no', 'production_order_id', 'warehouse_id',
        'mode', 'status', 'created_by',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(MaterialIssueLine::class);
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }
}
