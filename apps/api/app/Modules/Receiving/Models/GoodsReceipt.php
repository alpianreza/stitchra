<?php

namespace Modules\Receiving\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Purchasing\Models\PurchaseOrder;

class GoodsReceipt extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['DRAFT','SUBMITTED','POSTED','CANCELLED'];

    protected $fillable = [
        'company_id', 'doc_no', 'purchase_order_id', 'warehouse_id',
        'received_date', 'delivery_note_no', 'status', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['received_date' => 'date'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GrLine::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
