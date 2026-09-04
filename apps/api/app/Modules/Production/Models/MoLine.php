<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\MasterData\Models\Colorway;
use Modules\MasterData\Models\Size;
use Modules\Sales\Models\SalesOrderLine;

/** BR-020: immutable planning matrix copied from the confirmed SO line. */
class MoLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'production_order_id', 'sales_order_line_id',
        'colorway_id', 'size_id', 'qty_planned',
    ];

    protected function casts(): array
    {
        return ['qty_planned' => 'decimal:4'];
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class);
    }

    public function colorway(): BelongsTo
    {
        return $this->belongsTo(Colorway::class);
    }

    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class);
    }
}
