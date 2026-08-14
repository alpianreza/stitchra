<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Concerns\BelongsToCompany;

/** BR-013/BR-016: append-only. Tidak ada updated_at; koreksi hanya via entri balik. */
class StockLedger extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    public const TYPES = [
        'OPENING','PURCHASE_RECEIPT','PURCHASE_RETURN','QUALITY_RELEASE',
        'TRANSFER_IN','TRANSFER_OUT','MATERIAL_ISSUE','PRODUCTION_RETURN',
        'PRODUCTION_RECEIPT','ADJUSTMENT','OPNAME_ADJUSTMENT',
        'SUBCON_OUT','SUBCON_IN','SHIPMENT',
    ];

    protected $table = 'stock_ledger';

    protected $fillable = [
        'company_id', 'movement_type', 'item_type', 'material_id',
        'style_id', 'colorway_id', 'size_id',
        'warehouse_id', 'location_id', 'lot_no', 'roll_id', 'ownership',
        'qty_in', 'qty_out', 'uom_id', 'unit_cost', 'total_cost', 'running_balance',
        'source_document_type', 'source_document_id', 'source_document_line_id',
        'created_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'qty_in' => 'decimal:4', 'qty_out' => 'decimal:4',
            'unit_cost' => 'decimal:6', 'total_cost' => 'decimal:4',
            'running_balance' => 'decimal:4', 'created_at' => 'datetime',
        ];
    }
}
