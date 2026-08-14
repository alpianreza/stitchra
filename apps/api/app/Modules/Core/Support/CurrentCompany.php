<?php

namespace Modules\Core\Support;

/**
 * Menyimpan company aktif untuk request berjalan.
 * Diisi oleh middleware ResolveCompany, dibaca oleh BelongsToCompanyScope.
 */
class CurrentCompany
{
    private static ?int $id = null;

    public static function set(?int $id): void
    {
        self::$id = $id;
    }

    public static function id(): ?int
    {
        return self::$id;
    }

    public static function clear(): void
    {
        self::$id = null;
    }
}
