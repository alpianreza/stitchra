<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Concerns\BelongsToCompany;

/** BR-022: amendment — terkunci bila cutting sudah mulai (gate di service) */
class OrderAmendment extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['DRAFT','SUBMITTED','APPROVED','REJECTED','CANCELLED'];

    protected $fillable = [
        'company_id', 'doc_no', 'sales_order_id', 'line_delta',
        'reason', 'status', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['line_delta' => 'array'];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }
}
