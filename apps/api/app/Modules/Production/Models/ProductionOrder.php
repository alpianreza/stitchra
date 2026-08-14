<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\MasterData\Models\Line;
use Modules\MasterData\Models\Style;
use Modules\ProductDev\Models\BomVersion;
use Modules\ProductDev\Models\RoutingVersion;
use Modules\Sales\Models\SalesOrder;

/**
 * Manufacturing Order — per style dari SO CONFIRMED.
 * Snapshot bom_version_id & routing_version_id: perubahan BOM baru TIDAK mengubah MO berjalan (BR-030).
 */
class ProductionOrder extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['PLANNED','RELEASED','CUTTING','SEWING','FINISHING','QC','PACKED','CLOSED','CANCELLED'];

    protected $fillable = [
        'company_id', 'doc_no', 'sales_order_id', 'style_id',
        'bom_version_id', 'routing_version_id', 'line_id',
        'qty_planned', 'qty_produced', 'planned_start', 'planned_end',
        'actual_start', 'actual_end', 'status', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'qty_planned' => 'decimal:4', 'qty_produced' => 'decimal:4',
            'planned_start' => 'date', 'planned_end' => 'date',
            'actual_start' => 'date', 'actual_end' => 'date',
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function style(): BelongsTo
    {
        return $this->belongsTo(Style::class);
    }

    public function bomVersion(): BelongsTo
    {
        return $this->belongsTo(BomVersion::class);
    }

    public function routingVersion(): BelongsTo
    {
        return $this->belongsTo(RoutingVersion::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(Line::class);
    }

    public function materialAllocations(): HasMany
    {
        return $this->hasMany(MoMaterialAllocation::class);
    }
}
