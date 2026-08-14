<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;

class StockOpname extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['DRAFT','COUNTING','SUBMITTED','APPROVED','CANCELLED'];

    protected $fillable = ['company_id', 'doc_no', 'warehouse_id', 'opname_date', 'status', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['opname_date' => 'date'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockOpnameLine::class);
    }
}
