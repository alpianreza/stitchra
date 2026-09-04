<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\MasterData\Models\Supplier;

class PurchaseOrder extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['DRAFT','SUBMITTED','APPROVED','PARTIAL_RECEIVED','RECEIVED','CLOSED','REJECTED','CANCELLED'];

    protected $fillable = [
        'company_id', 'doc_no', 'supplier_id', 'currency_id', 'exchange_rate',
        'order_date', 'expected_date', 'payment_term', 'total_amount',
        'status', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date', 'expected_date' => 'date',
            'exchange_rate' => 'decimal:12', 'total_amount' => 'decimal:4',
        ];
    }

    public function lines(): HasMany { return $this->hasMany(PoLine::class)->orderBy('line_no'); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }

    public function refreshReceivingStatus(): void
    {
        $totalOrdered = (float) $this->lines()->sum('qty');
        $totalReceived = (float) $this->lines()->sum('received_qty');
        if ($totalReceived <= 0) return;
        $this->status = $totalReceived >= $totalOrdered ? 'RECEIVED' : 'PARTIAL_RECEIVED';
        $this->save();
    }
}
