<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'password', 'role_id', 'region_id', 'association_id', 'phone_encrypted', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token', 'phone_encrypted'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'phone_encrypted' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function donor()
    {
        return $this->hasOne(Donor::class);
    }

    public function provider()
    {
        return $this->hasOne(Provider::class);
    }

    public function hasRole(string ...$keys): bool
    {
        return in_array($this->role?->key, $keys, true);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /** council is read-only everywhere — see PermissionService::denyWrites(). */
    public function isReadOnly(): bool
    {
        return (bool) $this->role?->is_read_only;
    }

    public function can_(string $permissionKey): bool
    {
        return app(\App\Services\PermissionService::class)->has($this, $permissionKey);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->hasRole('admin', 'case_officer', 'area_supervisor', 'delegate', 'council'),
            'association' => $this->hasRole('association', 'admin'),
            'provider' => $this->hasRole('service_provider', 'admin'),
            default => false,
        };
    }
}
