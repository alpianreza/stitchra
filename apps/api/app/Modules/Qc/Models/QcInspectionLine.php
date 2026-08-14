<?php

namespace Modules\Qc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\MasterData\Models\DefectLibrary;

/** BR-072: defect selalu dari library; severity di-snapshot dari library */
class QcInspectionLine extends Model
{
    protected $fillable = [
        'qc_inspection_id', 'bundle_id', 'operation_id', 'defect_id',
        'severity', 'qty', 'photo_path', 'notes',
    ];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(QcInspection::class, 'qc_inspection_id');
    }

    public function defect(): BelongsTo
    {
        return $this->belongsTo(DefectLibrary::class, 'defect_id');
    }
}
