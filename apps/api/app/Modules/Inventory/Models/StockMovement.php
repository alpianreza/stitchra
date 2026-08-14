<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Concerns\BelongsToCompany;

/** Header dokumen movement — mengelompokkan ledger entries (BR-013) */
class StockMovement extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'doc_no', 'movement_type',
        'source_document_type', 'source_document_id', 'created_by',
    ];
}
