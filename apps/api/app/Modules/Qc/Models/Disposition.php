<?php

namespace Modules\Qc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Core\Models\User;

class Disposition extends Model
{
    use BelongsToCompany;

    public const ACTIONS = ['REWORK', 'REPAIR', 'REJECT', 'SECOND_GRADE', 'SCRAP'];
    public const REWORK_ACTIONS = ['REWORK', 'REPAIR'];
    public const TARGET_STAGES = ['CUTTING', 'SEWING', 'FINISHING'];

    protected $fillable = [
        'company_id', 'ncr_id', 'action', 'qty', 'target_stage', 'notes',
        'approved_by', 'approved_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4', 'approved_at' => 'datetime'];
    }

    public function ncr(): BelongsTo
    {
        return $this->belongsTo(Ncr::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reworkOrder(): HasOne
    {
        return $this->hasOne(ReworkOrder::class);
    }
}
