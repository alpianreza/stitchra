<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Concerns\BelongsToCompany;

/** BR-103: periode CLOSED tidak menerima posting jurnal */
class GlPeriod extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['OPEN', 'CLOSED'];

    protected $fillable = ['company_id', 'period', 'status', 'closed_by', 'closed_at'];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }
}
