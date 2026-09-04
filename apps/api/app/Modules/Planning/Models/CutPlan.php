<?php

namespace Modules\Planning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Cutting\Models\CutOrder;
use Modules\Production\Models\ProductionOrder;

class CutPlan extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'doc_no', 'production_order_id', 'planned_lay_count',
        'total_qty', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['total_qty' => 'decimal:4'];
    }

    public function productionOrder(): BelongsTo { return $this->belongsTo(ProductionOrder::class); }
    public function lays(): HasMany { return $this->hasMany(CutPlanLay::class); }
    public function cutOrders(): HasMany { return $this->hasMany(CutOrder::class); }
}
