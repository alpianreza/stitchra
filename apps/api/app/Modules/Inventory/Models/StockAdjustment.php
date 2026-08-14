<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;

/** BR-017: koreksi stok hanya via adjustment ber-approval */
class StockAdjustment extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['DRAFT','SUBMITTED','APPROVED','REJECTED','CANCELLED'];

    protected $fillable = ['company_id', 'doc_no', 'reason', 'status', 'created_by', 'updated_by'];

    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class);
    }
}
