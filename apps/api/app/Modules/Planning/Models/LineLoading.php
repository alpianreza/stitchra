<?php

namespace Modules\Planning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\MasterData\Models\Line;
use Modules\Production\Models\ProductionOrder;

class LineLoading extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'production_plan_id', 'line_id', 'production_order_id',
        'plan_date', 'planned_qty', 'capacity_snapshot', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'plan_date' => 'date',
            'planned_qty' => 'decimal:4',
            'capacity_snapshot' => 'decimal:4',
        ];
    }

    public function productionPlan(): BelongsTo { return $this->belongsTo(ProductionPlan::class); }
    public function line(): BelongsTo { return $this->belongsTo(Line::class); }
    public function productionOrder(): BelongsTo { return $this->belongsTo(ProductionOrder::class); }
}
