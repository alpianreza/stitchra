<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;

class PurchaseRequest extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['DRAFT','SUBMITTED','APPROVED','REJECTED','CANCELLED','CONVERTED'];
    public const SOURCES = ['MRP', 'MANUAL'];

    protected $fillable = ['company_id', 'doc_no', 'source', 'needed_by', 'status', 'notes', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['needed_by' => 'date'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PrLine::class);
    }
}
