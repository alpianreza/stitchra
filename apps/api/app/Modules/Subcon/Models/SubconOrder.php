<?php

namespace Modules\Subcon\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\MasterData\Models\Supplier;
use Modules\Production\Models\ProductionOrder;

/** BR-090/091: subcon CMT — stok keluar = in_transit_subcon; fee jasa → actual costing (BR-080) */
class SubconOrder extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['DRAFT','SENT','PARTIAL_RETURNED','RETURNED','CLOSED','CANCELLED'];

    protected $fillable = [
        'company_id', 'doc_no', 'supplier_id', 'production_order_id', 'operation_id',
        'sent_date', 'expected_return', 'fee_per_pcs', 'status', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'sent_date' => 'date', 'expected_return' => 'date',
            'fee_per_pcs' => 'decimal:6',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SubconOrderLine::class);
    }

    public function fees(): HasMany
    {
        return $this->hasMany(SubconFee::class);
    }
}
