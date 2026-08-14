<?php

namespace Modules\Subcon\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Fee jasa subcon per return — dipakai actual costing (BR-080) */
class SubconFee extends Model
{
    protected $fillable = ['subcon_order_id', 'return_date', 'qty_returned', 'fee_per_pcs', 'total_fee'];

    protected function casts(): array
    {
        return [
            'return_date' => 'date', 'qty_returned' => 'decimal:4',
            'fee_per_pcs' => 'decimal:6', 'total_fee' => 'decimal:4',
        ];
    }

    public function subconOrder(): BelongsTo
    {
        return $this->belongsTo(SubconOrder::class);
    }
}
