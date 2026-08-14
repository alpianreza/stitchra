<?php

namespace Modules\Receiving\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\MasterData\Models\Supplier;

/** BR-004: FAIL inward QC → return ke supplier + claim */
class SupplierReturn extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['DRAFT','SUBMITTED','APPROVED','SHIPPED','CLOSED','CANCELLED'];

    protected $fillable = [
        'company_id', 'doc_no', 'goods_receipt_id', 'supplier_id',
        'reason', 'claim_amount', 'status', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['claim_amount' => 'decimal:4'];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
