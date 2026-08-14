<?php

namespace Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentLine extends Model
{
    protected $fillable = ['shipment_id', 'style_id', 'colorway_id', 'size_id', 'qty_shipped'];

    protected function casts(): array
    {
        return ['qty_shipped' => 'decimal:4'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
