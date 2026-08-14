<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\MasterData\Models\Customer;

/** AR invoice dari shipment; kurs tersimpan (BR-102) */
class ArInvoice extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['OPEN', 'PARTIAL', 'PAID', 'VOID'];

    protected $fillable = [
        'company_id', 'doc_no', 'customer_id', 'sales_order_id', 'shipment_id',
        'invoice_date', 'due_date', 'currency_id', 'exchange_rate',
        'total_amount', 'paid_amount', 'status', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date', 'due_date' => 'date',
            'exchange_rate' => 'decimal:6', 'total_amount' => 'decimal:4',
            'paid_amount' => 'decimal:4',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ArInvoiceLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ArPayment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function outstanding(): float
    {
        return (float) $this->total_amount - (float) $this->paid_amount;
    }
}
