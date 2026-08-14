<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Concerns\BelongsToCompany;

class AuditLog extends Model
{
    use BelongsToCompany;

    /** BR-016: append-only — tanpa updated_at/deleted_at */
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'user_id', 'action', 'document_type', 'document_id',
        'document_line_id', 'before', 'after', 'ip_address', 'user_agent', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
