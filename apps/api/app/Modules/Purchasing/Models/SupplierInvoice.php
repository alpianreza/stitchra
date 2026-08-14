<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\MasterData\Models\Supplier;

/** BR-050: 3-way match (PO–GR–invoice) */
class SupplierInvoice extends Model
{
    use BelongsToCompany;

    public const MATCH_STATUSES = ['PENDING','MATCHED','MISMATCH'];
    public const STATUSES = ['DRAFT','SUBMITTED','APPROVED','REJECTED','PAID','CANCELLED'];

    protected $fillable = [
        'company_id', 'doc_no', 'supplier_id', 'purchase_order_id',
        'supplier_invoice_no', 'invoice_date', 'due_date', 'total_amount',
        'match_status', 'status', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date', 'due_date' => 'date',
            'total_amount' => 'decimal:4',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierInvoiceLine::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
