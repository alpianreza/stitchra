<?php

namespace Modules\Qc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Production\Models\ProductionOrder;

/**
 * BR-070/071: inspeksi QC per stage; FINAL = sampling AQL per buyer config (BR-008).
 * AQL di-snapshot ke header saat inspeksi dibuat — perubahan config buyer tidak mengubah histori.
 */
class QcInspection extends Model
{
    use BelongsToCompany;

    public const STAGES = ['INLINE', 'ENDLINE', 'FINAL'];
    public const VERDICTS = ['PENDING', 'PASS', 'FAIL', 'REWORK'];

    protected $fillable = [
        'company_id', 'doc_no', 'production_order_id', 'stage', 'customer_id',
        'inspection_level', 'aql_major', 'aql_minor', 'aql_critical',
        'lot_qty', 'sample_size', 'accept_major', 'reject_major',
        'defects_major', 'defects_minor', 'defects_critical',
        'cycle', 'verdict', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'lot_qty' => 'decimal:4',
            'aql_major' => 'decimal:2', 'aql_minor' => 'decimal:2', 'aql_critical' => 'decimal:2',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QcInspectionLine::class);
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function ncr(): HasOne
    {
        return $this->hasOne(Ncr::class);
    }

    public function sourceReworkOrders(): HasMany
    {
        return $this->hasMany(ReworkOrder::class, 'reinspection_id');
    }
}
