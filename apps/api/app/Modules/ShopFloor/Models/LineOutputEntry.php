<?php

namespace Modules\ShopFloor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Cutting\Models\Bundle;

class LineOutputEntry extends Model
{
    use BelongsToCompany;
    public $timestamps = false;
    protected $fillable = ['company_id','line_output_id','source_scan_id','bundle_id','qty','recorded_at','created_by','created_at'];
    protected function casts(): array { return ['qty'=>'decimal:4','recorded_at'=>'datetime','created_at'=>'datetime']; }
    protected static function booted(): void { static::updating(fn()=>throw new LogicException('Line output entry append-only.')); static::deleting(fn()=>throw new LogicException('Line output entry append-only.')); }
    public function lineOutput(): BelongsTo { return $this->belongsTo(LineOutput::class); }
    public function sourceScan(): BelongsTo { return $this->belongsTo(ProductionScan::class, 'source_scan_id'); }
    public function bundle(): BelongsTo { return $this->belongsTo(Bundle::class); }
}
