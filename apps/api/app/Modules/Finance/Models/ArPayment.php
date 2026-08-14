<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Concerns\BelongsToCompany;

class ArPayment extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'doc_no', 'ar_invoice_id', 'payment_date',
        'amount', 'method', 'reference_no', 'created_by',
    ];

    protected function casts(): array
    {
        return ['payment_date' => 'date', 'amount' => 'decimal:4'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ArInvoice::class, 'ar_invoice_id');
    }
}
