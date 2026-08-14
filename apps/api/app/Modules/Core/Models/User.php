<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Core\Models\Concerns\BelongsToCompany;

class User extends Authenticatable
{
    use HasApiTokens, SoftDeletes, BelongsToCompany;

    protected $fillable = [
        'company_id', 'name', 'email', 'password', 'is_active',
        'failed_logins', 'locked_until', 'last_login_at', 'created_by', 'updated_by',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',   // hashing modern (BR-111)
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'user_companies');
    }

    /** BR-110: cek permission granular domain.entity.action */
    public function hasPermission(string $permission): bool
    {
        return $this->roles()
            ->whereHas('permissions', fn ($q) => $q->where('code', $permission))
            ->exists();
    }

    public function isLockedOut(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
