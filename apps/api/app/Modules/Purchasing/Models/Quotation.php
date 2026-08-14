<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\MasterData\Models\Supplier;

class Quotation extends Model
{
    protected $fillable = ['rfq_id', 'supplier_id', 'currency', 'lead_time_days', 'payment_term', 'is_selected'];

    protected function casts(): array
    {
        return ['is_selected' => 'boolean'];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class);
    }
}
