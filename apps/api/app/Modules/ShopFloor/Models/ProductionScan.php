<?php

namespace Modules\ShopFloor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Cutting\Models\Bundle;
use Modules\MasterData\Models\Employee;
use Modules\MasterData\Models\Line;
use Modules\MasterData\Models\Operation;
use Modules\Production\Models\ProductionOrder;

/** BR-062: scan IN/OUT bundle per operasi = bukti kehadiran fisik; BR-063: WIP dari scan */
class ProductionScan extends Model
{
    use BelongsToCompany;

    public const DIRECTIONS = ['IN', 'OUT'];
    public const STAGES = ['SEWING', 'FINISHING'];

    protected $fillable = [
        'company_id', 'bundle_id', 'operation_id', 'production_order_id',
        'line_id', 'employee_id', 'direction', 'stage', 'scanned_at',
    ];

    protected function casts(): array
    {
        return ['scanned_at' => 'datetime'];
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(Line::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
