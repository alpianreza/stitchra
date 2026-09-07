<?php

namespace Modules\Receiving\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\StockLedger;

class SupplierReturnLine extends Model
{
    protected $hidden = ['unit_cost'];

    protected $fillable = [
        'supplier_return_id', 'receipt_ledger_id', 'gr_line_id', 'roll_id', 'qty', 'uom_id', 'unit_cost',
    ];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4', 'unit_cost' => 'decimal:6'];
    }

    public function supplierReturn(): BelongsTo
    {
        return $this->belongsTo(SupplierReturn::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(StockLedger::class, 'receipt_ledger_id');
    }
}
