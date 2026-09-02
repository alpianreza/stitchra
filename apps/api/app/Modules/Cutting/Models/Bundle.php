<?php

namespace Modules\Cutting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Production\Models\ProductionOrder;
use Modules\ShopFloor\Models\ProductionScan;

class Bundle extends Model
{
    use BelongsToCompany;

    public const STAGES = ['CUTTING','SEWING','FINISHING','QC','PACKING'];
    public const STATUSES = ['ACTIVE','COMPLETED','REWORK','REJECTED'];

    protected $fillable = ['company_id','bundle_no','cut_order_line_id','cut_output_id','production_order_id','qty','current_stage','status','scan_version'];
    protected function casts(): array { return ['qty'=>'decimal:4','scan_version'=>'integer']; }
    public function cutOrderLine(): BelongsTo { return $this->belongsTo(CutOrderLine::class, 'cut_order_line_id'); }
    public function cutOutput(): BelongsTo { return $this->belongsTo(CutOutput::class); }
    public function productionOrder(): BelongsTo { return $this->belongsTo(ProductionOrder::class); }
    public function scans(): HasMany { return $this->hasMany(ProductionScan::class); }
}
