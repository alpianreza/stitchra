<?php

namespace Modules\ShopFloor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\MasterData\Models\Line;
use Modules\Production\Models\ProductionOrder;

class LineOutput extends Model
{
    use BelongsToCompany;
    protected $fillable = ['company_id','production_order_id','line_id','output_date','qty','target_qty','achievement_pct','created_by','updated_by'];
    protected function casts(): array { return ['output_date'=>'date','qty'=>'decimal:4','target_qty'=>'decimal:4','achievement_pct'=>'decimal:4']; }
    public function productionOrder(): BelongsTo { return $this->belongsTo(ProductionOrder::class); }
    public function line(): BelongsTo { return $this->belongsTo(Line::class); }
    public function entries(): HasMany { return $this->hasMany(LineOutputEntry::class); }
}
