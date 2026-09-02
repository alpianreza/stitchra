<?php

namespace Modules\Subcon\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\MasterData\Models\Warehouse;

/** BR-091 service charge per vendor return; also identifies one idempotent receipt boundary. */
class SubconFee extends Model
{
    protected $fillable = [
        'subcon_order_id', 'subcon_order_line_id', 'warehouse_id', 'receipt_reference',
        'return_date', 'qty_returned', 'fee_per_pcs', 'total_fee',
    ];

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'qty_returned' => 'decimal:4',
            'fee_per_pcs' => 'decimal:6',
            'total_fee' => 'decimal:4',
        ];
    }

    public function subconOrder(): BelongsTo
    {
        return $this->belongsTo(SubconOrder::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(SubconOrderLine::class, 'subcon_order_line_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
