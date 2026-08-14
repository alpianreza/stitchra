<?php

namespace Modules\Receiving\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\MasterData\Models\DefectLibrary;

class InwardInspectionLine extends Model
{
    protected $fillable = [
        'inward_inspection_id', 'gr_line_id', 'roll_id',
        'four_point_points', 'shrinkage_pct_actual', 'gsm_actual',
        'shade_verdict', 'defect_id', 'result', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'four_point_points' => 'decimal:2', 'shrinkage_pct_actual' => 'decimal:4',
            'gsm_actual' => 'decimal:2',
        ];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(InwardInspection::class, 'inward_inspection_id');
    }

    public function roll(): BelongsTo
    {
        return $this->belongsTo(FabricRoll::class, 'roll_id');
    }

    public function defect(): BelongsTo
    {
        return $this->belongsTo(DefectLibrary::class, 'defect_id');
    }
}
