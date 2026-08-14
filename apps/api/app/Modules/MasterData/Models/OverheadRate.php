<?php

namespace Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Concerns\BelongsToCompany;

/** BR-009: OH rate per menit per company per periode (overhead = Σ SAM×output × rate) */
class OverheadRate extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'period', 'rate_per_minute', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['rate_per_minute' => 'decimal:6'];
    }
}
