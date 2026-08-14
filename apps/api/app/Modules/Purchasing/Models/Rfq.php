<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;

class Rfq extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['OPEN','CLOSED','AWARDED','CANCELLED'];

    protected $fillable = ['company_id', 'doc_no', 'status', 'deadline', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['deadline' => 'date', 'is_selected' => 'boolean'];
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }
}
