<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\MasterData\Models\Currency;
use Modules\MasterData\Models\Customer;

class SalesOrder extends Model
{
    use SoftDeletes, BelongsToCompany;

    public const STATUSES = ['DRAFT','SUBMITTED','APPROVED','CONFIRMED','IN_PROGRESS','CLOSED','REJECTED','CANCELLED'];

    protected $fillable = [
        'company_id', 'doc_no', 'customer_id', 'buyer_po_no', 'currency_id',
        'exchange_rate', 'order_date', 'ex_factory_date', 'tolerance_pct',
        'status', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'ex_factory_date' => 'date',
            'exchange_rate' => 'decimal:6',
            'tolerance_pct' => 'decimal:4',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(OrderAmendment::class);
    }

    public function deliverySchedules(): HasMany
    {
        return $this->hasMany(DeliverySchedule::class);
    }
}
