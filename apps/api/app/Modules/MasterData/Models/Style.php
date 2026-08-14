<?php

namespace Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Concerns\BelongsToCompany;

/**
 * Style master. CATATAN (BR-020): Style ≠ SKU.
 * SKU = style × colorway × size — direpresentasikan di transaction lines, bukan tabel ini.
 */
class Style extends Model
{
    use SoftDeletes, BelongsToCompany;

    public const CATEGORIES = ['WOVEN', 'KNIT', 'OTHER'];
    public const LIFECYCLES = ['DEVELOPMENT', 'ACTIVE', 'DISCONTINUED'];

    protected $fillable = [
        'company_id', 'style_no', 'buyer_style_ref', 'customer_id', 'season',
        'category', 'product_group', 'lifecycle', 'description', 'created_by', 'updated_by',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function colorways(): HasMany
    {
        return $this->hasMany(Colorway::class);
    }
}
