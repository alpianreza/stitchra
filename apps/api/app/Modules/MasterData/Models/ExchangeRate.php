<?php

namespace Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Concerns\BelongsToCompany;

class ExchangeRate extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'currency_id', 'rate_date', 'rate'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:6', 'rate_date' => 'date'];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
