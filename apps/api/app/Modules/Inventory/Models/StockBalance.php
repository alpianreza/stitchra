<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Concerns\BelongsToCompany;

/**
 * Saldo materialized dari ledger. BR-006:
 *   available = on_hand − reserved − quality_hold
 * CHECK DB menjamin tidak ada saldo negatif; ITS menjaga invariant di service.
 */
class StockBalance extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'item_type', 'material_id', 'style_id', 'colorway_id', 'size_id',
        'warehouse_id', 'location_id', 'lot_no', 'roll_id', 'ownership',
        'on_hand', 'reserved', 'quality_hold', 'in_transit_subcon', 'avg_cost',
    ];

    protected function casts(): array
    {
        return [
            'on_hand' => 'decimal:4', 'reserved' => 'decimal:4',
            'quality_hold' => 'decimal:4', 'in_transit_subcon' => 'decimal:4',
            'avg_cost' => 'decimal:6',
        ];
    }

    /** BR-006 */
    public function available(): float
    {
        return (float) $this->on_hand - (float) $this->reserved - (float) $this->quality_hold;
    }
}
