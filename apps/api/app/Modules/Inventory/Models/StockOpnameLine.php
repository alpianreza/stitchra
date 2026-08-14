<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StockOpnameLine extends Model
{
    protected $fillable = [
        'stock_opname_id', 'material_id', 'location_id', 'lot_no', 'roll_id',
        'system_qty', 'counted_qty', 'variance_qty',
    ];

    protected function casts(): array
    {
        return [
            'system_qty' => 'decimal:4', 'counted_qty' => 'decimal:4',
            'variance_qty' => 'decimal:4',
        ];
    }
}
