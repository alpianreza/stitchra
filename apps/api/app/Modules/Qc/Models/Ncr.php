<?php

namespace Modules\Qc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Production\Models\ProductionOrder;

class Ncr extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED', 'CLOSED', 'CANCELLED'];

    protected $fillable = [
        'company_id', 'doc_no', 'qc_inspection_id', 'production_order_id',
        'qty', 'status', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4'];
    }

    public function qcInspection(): BelongsTo
    {
        return $this->belongsTo(QcInspection::class);
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function dispositions(): HasMany
    {
        return $this->hasMany(Disposition::class);
    }

    public function reworkOrders(): HasMany
    {
        return $this->hasMany(ReworkOrder::class);
    }
}
