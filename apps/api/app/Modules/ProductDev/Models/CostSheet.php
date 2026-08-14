<?php

namespace Modules\ProductDev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\MasterData\Models\Style;

/** BR-100: estimated cost sheet per style; APPROVED = standard cost */
class CostSheet extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['DRAFT','SUBMITTED','APPROVED','OBSOLETE'];

    protected $fillable = [
        'company_id', 'doc_no', 'style_id', 'bom_version_id', 'routing_version_id',
        'version', 'fabric_cost', 'trim_cost', 'cm_cost', 'overhead_cost',
        'subcon_cost', 'other_cost', 'fob_price', 'margin_pct', 'status',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return collect([
            'fabric_cost', 'trim_cost', 'cm_cost', 'overhead_cost',
            'subcon_cost', 'other_cost', 'fob_price',
        ])->mapWithKeys(fn ($f) => [$f => 'decimal:4'])->all()
            + ['margin_pct' => 'decimal:4'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CostSheetLine::class);
    }

    public function style(): BelongsTo
    {
        return $this->belongsTo(Style::class);
    }

    public function totalManufacturingCost(): float
    {
        return (float) $this->fabric_cost + (float) $this->trim_cost
            + (float) $this->cm_cost + (float) $this->overhead_cost
            + (float) $this->subcon_cost + (float) $this->other_cost;
    }
}
