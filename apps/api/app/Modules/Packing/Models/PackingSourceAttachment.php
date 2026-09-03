<?php

namespace Modules\Packing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\ApprovalRequest;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Core\Models\User;
use Modules\Qc\Models\QcInspection;

/** BR-068: immutable proposal evidence for an approved legacy QC source attachment. */
class PackingSourceAttachment extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'packing_list_id', 'qc_inspection_id', 'reason',
        'approval_request_id', 'requested_by', 'applied_by', 'applied_at',
    ];

    protected function casts(): array
    {
        return ['applied_at' => 'datetime'];
    }

    public function packingList(): BelongsTo
    {
        return $this->belongsTo(PackingList::class);
    }

    public function qcInspection(): BelongsTo
    {
        return $this->belongsTo(QcInspection::class);
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
