<?php

namespace Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;

class Currency extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'code', 'name', 'symbol'];

    public function rates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class);
    }

    /** Rate terbaru pada tanggal tertentu (BR-102) */
    public function rateAt(?string $date = null): ?float
    {
        $date = $date ?? now()->toDateString();

        $rate = $this->rates()
            ->where('rate_date', '<=', $date)
            ->orderByDesc('rate_date')
            ->first();

        return $rate ? (float) $rate->rate : null;
    }
}
