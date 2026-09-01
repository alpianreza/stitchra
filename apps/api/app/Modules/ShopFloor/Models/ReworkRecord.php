<?php

namespace Modules\ShopFloor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Cutting\Models\Bundle;
use Modules\MasterData\Models\DefectLibrary;
use Modules\MasterData\Models\Operation;

class ReworkRecord extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id','bundle_id','operation_id','defect_id','qty','notes','created_by','resolved_at','resolved_by'];
    protected function casts(): array { return ['qty'=>'decimal:4','resolved_at'=>'datetime']; }
    public function bundle(): BelongsTo { return $this->belongsTo(Bundle::class); }
    public function operation(): BelongsTo { return $this->belongsTo(Operation::class); }
    public function defect(): BelongsTo { return $this->belongsTo(DefectLibrary::class, 'defect_id'); }
}
