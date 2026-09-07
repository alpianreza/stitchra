<?php

namespace Modules\Receiving\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\StockLedger;
use Modules\MasterData\Models\Location;

class PutawayLine extends Model
{
    protected $hidden = ['unit_cost'];

    protected $fillable = [
        'putaway_id', 'receipt_ledger_id', 'gr_line_id', 'roll_id', 'from_location_id', 'to_location_id',
        'qty', 'uom_id', 'unit_cost',
    ];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4', 'unit_cost' => 'decimal:6'];
    }

    public function putaway(): BelongsTo
    {
        return $this->belongsTo(Putaway::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(StockLedger::class, 'receipt_ledger_id');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }
}
