<?php

namespace Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Concerns\BelongsToCompany;

/** BR-008: AQL per buyer (default G-II, 2.5 major / 4.0 minor, critical 0) */
class CustomerAqlConfig extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'customer_id', 'inspection_level',
        'aql_critical', 'aql_major', 'aql_minor', 'report_format',
    ];

    protected function casts(): array
    {
        return [
            'aql_critical' => 'decimal:2',
            'aql_major' => 'decimal:2',
            'aql_minor' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
