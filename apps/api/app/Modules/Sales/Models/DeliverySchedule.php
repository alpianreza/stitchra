<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliverySchedule extends Model
{
    protected $fillable = ['sales_order_id', 'delivery_date', 'qty', 'destination'];

    protected function casts(): array
    {
        return ['delivery_date' => 'date', 'qty' => 'decimal:4'];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }
}
