<?php

namespace Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Concerns\BelongsToCompany;

/** Cost-per-minute per line per periode — CM costing (CM = total SAM × cost/min) */
class LineCostRate extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'line_id', 'period', 'cost_per_minute', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['cost_per_minute' => 'decimal:6'];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(Line::class);
    }
}
