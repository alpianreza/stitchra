<?php

namespace Modules\Packing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Sales\Models\SalesOrder;

class PackingInstruction extends Model
{
    use BelongsToCompany;

    public const TYPES = ['SOLID', 'RATIO', 'MIXED'];

    protected $fillable = ['company_id', 'sales_order_id', 'version', 'pack_type', 'is_active', 'created_by', 'updated_by'];
    protected function casts(): array { return ['version' => 'integer', 'is_active' => 'boolean']; }
    public function salesOrder(): BelongsTo { return $this->belongsTo(SalesOrder::class); }
    public function lines(): HasMany { return $this->hasMany(PackingInstructionLine::class); }
}
