<?php

namespace Modules\Packing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Production\Models\ProductionOrder;
use Modules\Sales\Models\SalesOrder;

class PackingList extends Model
{
    use BelongsToCompany;
    public const STATUSES=['DRAFT','SUBMITTED','APPROVED','SHIPPED','CANCELLED'];
    protected $fillable=['company_id','doc_no','sales_order_id','production_order_id','status','created_by','updated_by'];
    public function salesOrder(): BelongsTo{return $this->belongsTo(SalesOrder::class);}
    public function productionOrder(): BelongsTo{return $this->belongsTo(ProductionOrder::class);}
    public function cartons(): HasMany{return $this->hasMany(Carton::class);}
}
