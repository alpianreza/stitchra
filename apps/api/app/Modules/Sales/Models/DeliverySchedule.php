<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Shipping\Models\Shipment;

class DeliverySchedule extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['OPEN', 'FULFILLED', 'CANCELLED'];

    protected $fillable = ['company_id', 'sales_order_id', 'delivery_date', 'qty', 'destination', 'status', 'created_by', 'updated_by'];
    protected function casts(): array { return ['delivery_date' => 'date', 'qty' => 'decimal:4']; }
    public function salesOrder(): BelongsTo { return $this->belongsTo(SalesOrder::class); }
    public function shipments(): HasMany { return $this->hasMany(Shipment::class); }
}
