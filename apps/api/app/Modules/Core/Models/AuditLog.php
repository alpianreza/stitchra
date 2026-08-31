<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;
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

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Audit log bersifat append-only dan tidak dapat diubah.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Audit log bersifat append-only dan tidak dapat dihapus.');
        });
    }

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
