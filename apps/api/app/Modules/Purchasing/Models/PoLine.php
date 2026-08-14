<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\MasterData\Models\Material;

class PoLine extends Model
{
    protected $fillable = [
        'purchase_order_id', 'line_no', 'material_id', 'qty', 'uom_id',
        'unit_price', 'received_qty', 'pr_line_id',
    ];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4', 'unit_price' => 'decimal:6', 'received_qty' => 'decimal:4'];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
