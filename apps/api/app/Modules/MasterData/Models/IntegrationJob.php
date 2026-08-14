<?php

namespace Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Concerns\BelongsToCompany;

class IntegrationJob extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['PENDING', 'PROCESSING', 'DONE', 'FAILED'];

    protected $fillable = [
        'company_id', 'type', 'entity', 'file_path', 'status',
        'total_rows', 'success_rows', 'failed_rows', 'errors', 'created_by',
    ];

    protected function casts(): array
    {
        return ['errors' => 'array'];
    }
}
