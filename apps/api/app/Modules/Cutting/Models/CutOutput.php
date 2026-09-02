<?php
namespace Modules\Cutting\Models;
use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;use Illuminate\Database\Eloquent\Relations\HasMany;use Modules\Core\Models\Concerns\BelongsToCompany;
class CutOutput extends Model { use BelongsToCompany; protected $fillable=['company_id','lay_id','cut_order_line_id','qty_cut','created_by']; protected function casts():array{return['qty_cut'=>'decimal:4'];} public function lay():BelongsTo{return $this->belongsTo(Lay::class);} public function cutOrderLine():BelongsTo{return $this->belongsTo(CutOrderLine::class);} public function bundles():HasMany{return $this->hasMany(Bundle::class);} }
