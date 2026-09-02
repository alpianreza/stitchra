<?php

namespace Modules\Planning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ProductDev\Models\BomLine;
use Modules\Sales\Models\SalesOrderLine;

/** BR-121: trace perhitungan MRP — requirement → SO line → BOM line (kontribusi gross). */
class MrpTraceLine extends Model
{
    protected $fillable = ['mrp_requirement_id', 'sales_order_line_id', 'bom_line_id', 'gross_qty'];

    protected function casts(): array
    {
        return ['gross_qty' => 'decimal:4'];
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(MrpRequirement::class, 'mrp_requirement_id');
    }

    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id');
    }

    public function bomLine(): BelongsTo
    {
        return $this->belongsTo(BomLine::class, 'bom_line_id');
    }
}
