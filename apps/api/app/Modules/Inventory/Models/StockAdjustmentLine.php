<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StockAdjustmentLine extends Model
{
    protected $fillable = [
        'stock_adjustment_id', 'material_id', 'warehouse_id', 'location_id',
        'lot_no', 'roll_id', 'qty_delta', 'unit_cost', 'uom_id',
    ];

    protected function casts(): array
    {
        return ['qty_delta' => 'decimal:4', 'unit_cost' => 'decimal:6'];
    }
}
