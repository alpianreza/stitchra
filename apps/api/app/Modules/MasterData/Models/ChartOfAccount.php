<?php

namespace Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Concerns\BelongsToCompany;

/** BR-101: COA untuk full GL internal */
class ChartOfAccount extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $table = 'chart_of_accounts';

    public const TYPES = ['ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE'];
    public const NORMAL_BALANCES = ['DEBIT', 'CREDIT'];

    protected $fillable = [
        'company_id', 'code', 'name', 'type', 'normal_balance',
        'parent_id', 'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
