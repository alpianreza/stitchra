<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrLine extends Model
{
    protected $fillable = ['purchase_request_id', 'material_id', 'qty', 'uom_id', 'need_date', 'mrp_requirement_id'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4', 'need_date' => 'date'];
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }
}
