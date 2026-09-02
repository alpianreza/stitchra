<?php

namespace Modules\Cutting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\MasterData\Models\Colorway;
use Modules\MasterData\Models\Size;

class CutOrderLine extends Model
{
    protected $fillable = ['cut_order_id', 'colorway_id', 'size_id', 'qty_cut'];
    protected function casts(): array { return ['qty_cut' => 'decimal:4']; }
    public function cutOrder(): BelongsTo { return $this->belongsTo(CutOrder::class); }
    public function colorway(): BelongsTo { return $this->belongsTo(Colorway::class); }
    public function size(): BelongsTo { return $this->belongsTo(Size::class); }
    public function bundles(): HasMany { return $this->hasMany(Bundle::class, 'cut_order_line_id'); }
    public function cutOutputs(): HasMany { return $this->hasMany(CutOutput::class); }
}
