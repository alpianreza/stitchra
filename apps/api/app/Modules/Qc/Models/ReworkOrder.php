<?php

namespace Modules\Qc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Cutting\Models\Bundle;
use Modules\ShopFloor\Models\ReworkRecord;

class ReworkOrder extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'ncr_id', 'disposition_id', 'bundle_id', 'target_stage',
        'qty', 'rework_count', 'reinspection_id', 'status', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4'];
    }

    public function ncr(): BelongsTo { return $this->belongsTo(Ncr::class); }
    public function disposition(): BelongsTo { return $this->belongsTo(Disposition::class); }
    public function bundle(): BelongsTo { return $this->belongsTo(Bundle::class); }
    public function reinspection(): BelongsTo { return $this->belongsTo(QcInspection::class, 'reinspection_id'); }
    public function records(): HasMany { return $this->hasMany(ReworkRecord::class); }
}
