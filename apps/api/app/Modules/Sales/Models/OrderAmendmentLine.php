<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Concerns\BelongsToCompany;

class OrderAmendmentLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'order_amendment_id', 'sales_order_line_id',
        'old_qty', 'new_qty', 'qty_delta', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['old_qty' => 'decimal:4', 'new_qty' => 'decimal:4', 'qty_delta' => 'decimal:4'];
    }

    public function amendment(): BelongsTo { return $this->belongsTo(OrderAmendment::class, 'order_amendment_id'); }
    public function salesOrderLine(): BelongsTo { return $this->belongsTo(SalesOrderLine::class); }
}
