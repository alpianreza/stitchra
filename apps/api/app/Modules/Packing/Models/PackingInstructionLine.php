<?php

namespace Modules\Packing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\MasterData\Models\Colorway;
use Modules\MasterData\Models\Size;
use Modules\MasterData\Models\Style;

class PackingInstructionLine extends Model
{
    protected $fillable = ['packing_instruction_id', 'style_id', 'colorway_id', 'size_id', 'ratio_qty'];
    protected function casts(): array { return ['ratio_qty' => 'integer']; }
    public function instruction(): BelongsTo { return $this->belongsTo(PackingInstruction::class, 'packing_instruction_id'); }
    public function style(): BelongsTo { return $this->belongsTo(Style::class); }
    public function colorway(): BelongsTo { return $this->belongsTo(Colorway::class); }
    public function size(): BelongsTo { return $this->belongsTo(Size::class); }
}
