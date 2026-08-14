<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StockTransferLine extends Model
{
    protected $fillable = ['stock_transfer_id', 'material_id', 'lot_no', 'roll_id', 'qty', 'uom_id'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4'];
    }
}
