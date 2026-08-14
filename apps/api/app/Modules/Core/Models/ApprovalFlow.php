<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;

class ApprovalFlow extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'doc_type', 'version', 'mode', 'is_active', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalFlowStep::class, 'flow_id')->orderBy('step_no');
    }
}
