<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Purchasing\Models\SupplierInvoice;

/** Pembayaran ke supplier — hanya untuk invoice MATCHED (BR-050, divalidasi service) */
class ApPayment extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'doc_no', 'supplier_invoice_id', 'payment_date',
        'amount', 'method', 'reference_no', 'created_by',
    ];

    protected function casts(): array
    {
        return ['payment_date' => 'date', 'amount' => 'decimal:4'];
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }
}
