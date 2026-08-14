<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;

class ApprovalRequest extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['PENDING', 'APPROVED', 'REJECTED', 'REVISION', 'CANCELLED'];

    protected $fillable = [
        'company_id', 'flow_id', 'doc_type', 'doc_id', 'status',
        'current_step', 'is_active', 'submitted_by', 'submitted_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(ApprovalFlow::class, 'flow_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function stepInstances(): HasMany
    {
        return $this->hasMany(ApprovalStepInstance::class, 'request_id')->orderBy('step_no');
    }
}
