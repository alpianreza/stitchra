<?php

namespace Modules\ProductDev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\MasterData\Models\Style;

class Sample extends Model
{
    use BelongsToCompany;

    public const STAGES = ['PROTO','FIT','PP','TOP'];
    public const BUYER_STATUSES = ['PENDING','APPROVED','REJECTED','COMMENTED'];

    protected $fillable = [
        'company_id', 'doc_no', 'style_id', 'stage', 'version',
        'buyer_status', 'created_by', 'updated_by',
    ];

    public function style(): BelongsTo
    {
        return $this->belongsTo(Style::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(SampleApproval::class);
    }
}
