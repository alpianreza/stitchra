<?php

namespace Modules\ProductDev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\MasterData\Models\Style;

class TechPack extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'style_id', 'file_path', 'file_name', 'version', 'created_by',
        'disk', 'mime_type', 'size_bytes', 'sha256', 'revision_notes', 'upload_key',
    ];

    protected $hidden = ['file_path', 'disk', 'upload_key'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'version' => 'integer'];
    }

    public function style(): BelongsTo
    {
        return $this->belongsTo(Style::class);
    }
}
