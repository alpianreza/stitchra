<?php

namespace Modules\Receiving\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;

class InwardInspection extends Model
{
    use BelongsToCompany;

    public const RESULTS = ['PENDING','PASS','FAIL','PARTIAL'];

    protected $fillable = [
        'company_id', 'doc_no', 'goods_receipt_id', 'inspector_id',
        'result', 'notes', 'created_by', 'updated_by',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(InwardInspectionLine::class);
    }
}
