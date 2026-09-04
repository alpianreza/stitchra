<?php

namespace Modules\Planning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\MasterData\Models\Line;
use Modules\MasterData\Models\Style;
use Modules\Sales\Models\SalesOrder;

class ProductionPlan extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'sales_order_id', 'style_id', 'line_id',
        'period_start', 'period_end', 'target_qty', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'target_qty' => 'decimal:4',
        ];
    }

    public function salesOrder(): BelongsTo { return $this->belongsTo(SalesOrder::class); }
    public function style(): BelongsTo { return $this->belongsTo(Style::class); }
    public function line(): BelongsTo { return $this->belongsTo(Line::class); }
    public function loadings(): HasMany { return $this->hasMany(LineLoading::class); }
}
