<?php

namespace Modules\Planning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\MasterData\Models\Colorway;

class CutPlanLay extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'cut_plan_id', 'lay_sequence', 'colorway_id',
        'layer_count', 'estimated_marker_length_m', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['estimated_marker_length_m' => 'decimal:3'];
    }

    public function cutPlan(): BelongsTo { return $this->belongsTo(CutPlan::class); }
    public function colorway(): BelongsTo { return $this->belongsTo(Colorway::class); }
    public function ratios(): HasMany { return $this->hasMany(CutPlanLayRatio::class); }
}
