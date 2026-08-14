<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Concerns\BelongsToCompany;

/** BR-006/060: hard reservation saat MO release */
class StockReservation extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['ACTIVE', 'PARTIAL_ISSUED', 'FULLY_ISSUED', 'RELEASED'];

    protected $fillable = [
        'company_id', 'mo_id', 'material_id', 'warehouse_id', 'location_id',
        'lot_no', 'roll_id', 'ownership', 'qty_reserved', 'qty_issued',
        'status', 'created_by',
    ];

    protected function casts(): array
    {
        return ['qty_reserved' => 'decimal:4', 'qty_issued' => 'decimal:4'];
    }

    public function remaining(): float
    {
        return (float) $this->qty_reserved - (float) $this->qty_issued;
    }
}
