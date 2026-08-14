<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\MasterData\Models\Colorway;
use Modules\MasterData\Models\Size;
use Modules\MasterData\Models\Style;

/** BR-020: matrix line — style × colorway × size */
class SalesOrderLine extends Model
{
    protected $fillable = ['sales_order_id', 'style_id', 'colorway_id', 'size_id', 'qty', 'price'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4', 'price' => 'decimal:4'];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function style(): BelongsTo
    {
        return $this->belongsTo(Style::class);
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
