<?php

namespace Modules\Planning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\MasterData\Models\Size;

class CutPlanLayRatio extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'cut_plan_lay_id', 'size_id', 'ratio_qty', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['ratio_qty' => 'decimal:4'];
    }

    public function cutPlanLay(): BelongsTo { return $this->belongsTo(CutPlanLay::class); }
    public function size(): BelongsTo { return $this->belongsTo(Size::class); }
}
