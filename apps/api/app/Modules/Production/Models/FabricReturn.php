<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Receiving\Models\FabricRoll;

class FabricReturn extends Model
{
    use BelongsToCompany;
    protected $fillable = [
        'company_id','doc_no','production_order_id','roll_id','warehouse_id','uom_id',
        'qty_returned_meter','qty_returned_use','qty_dispatched_use','qty_consumed_use','reason','created_by',
    ];
    protected function casts(): array
    {
        return ['qty_returned_meter'=>'decimal:4','qty_returned_use'=>'decimal:4','qty_dispatched_use'=>'decimal:4','qty_consumed_use'=>'decimal:4'];
    }
    public function productionOrder(): BelongsTo { return $this->belongsTo(ProductionOrder::class); }
    public function roll(): BelongsTo { return $this->belongsTo(FabricRoll::class, 'roll_id'); }
}
