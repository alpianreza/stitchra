<?php

namespace Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Concerns\BelongsToCompany;

/** BR-072: defect selalu dari library (kategori + severity terkontrol), tidak free-text */
class DefectLibrary extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $table = 'defect_library';

    public const CATEGORIES = ['FABRIC', 'WORKMANSHIP', 'MEASUREMENT', 'PACKAGING', 'OTHER'];
    public const SEVERITIES = ['CRITICAL', 'MAJOR', 'MINOR'];

    protected $fillable = [
        'company_id', 'code', 'name', 'category', 'severity',
        'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
