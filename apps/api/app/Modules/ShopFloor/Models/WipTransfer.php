<?php

namespace Modules\ShopFloor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Cutting\Models\Bundle;
use Modules\Production\Models\ProductionOrder;

class WipTransfer extends Model
{
    use BelongsToCompany;

    public $timestamps = false;
    protected $fillable = ['company_id','production_order_id','bundle_id','source_scan_id','from_stage','to_stage','qty','transferred_at','created_by','created_at'];
    protected function casts(): array { return ['qty'=>'decimal:4','transferred_at'=>'datetime','created_at'=>'datetime']; }
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('WIP transfer bersifat append-only.'));
        static::deleting(fn () => throw new LogicException('WIP transfer bersifat append-only.'));
    }
    public function bundle(): BelongsTo { return $this->belongsTo(Bundle::class); }
    public function productionOrder(): BelongsTo { return $this->belongsTo(ProductionOrder::class); }
    public function sourceScan(): BelongsTo { return $this->belongsTo(ProductionScan::class, 'source_scan_id'); }
}
