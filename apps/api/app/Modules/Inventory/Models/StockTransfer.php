<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;

class StockTransfer extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['DRAFT','SUBMITTED','APPROVED','IN_TRANSIT','RECEIVED','CANCELLED'];

    protected $fillable = [
        'company_id', 'doc_no', 'from_warehouse_id', 'to_warehouse_id',
        'status', 'notes', 'created_by', 'updated_by',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class);
    }
}
