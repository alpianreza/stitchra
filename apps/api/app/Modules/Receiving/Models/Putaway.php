<?php

namespace Modules\Receiving\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;

class Putaway extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['DRAFT', 'POSTED', 'CANCELLED'];

    protected $fillable = ['company_id', 'doc_no', 'goods_receipt_id', 'status', 'notes', 'posted_at', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PutawayLine::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }
}
