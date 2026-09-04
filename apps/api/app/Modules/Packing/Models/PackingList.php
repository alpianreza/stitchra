<?php

namespace Modules\Packing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Production\Models\ProductionOrder;
use Modules\Qc\Models\QcInspection;
use Modules\Sales\Models\SalesOrder;
use Modules\Shipping\Models\Shipment;

class PackingList extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['DRAFT','SUBMITTED','APPROVED','SHIPPED','CANCELLED'];

    protected $fillable = [
        'company_id', 'doc_no', 'sales_order_id', 'production_order_id',
        'qc_inspection_id', 'packing_instruction_id', 'status', 'created_by', 'updated_by',
    ];

    public function salesOrder(): BelongsTo{return $this->belongsTo(SalesOrder::class);}
    public function productionOrder(): BelongsTo{return $this->belongsTo(ProductionOrder::class);}
    public function qcInspection(): BelongsTo{return $this->belongsTo(QcInspection::class);}
    public function packingInstruction(): BelongsTo{return $this->belongsTo(PackingInstruction::class);}
    public function cartons(): HasMany{return $this->hasMany(Carton::class);}
    public function sourceAttachments(): HasMany{return $this->hasMany(PackingSourceAttachment::class);}
    public function shipment(): HasOne{return $this->hasOne(Shipment::class);}
}
