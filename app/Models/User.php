<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\PermissionService;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    /*
     | T-95 — rule 4 counts a permission change among the writes that must be
     | logged, and the account is where a permission lives: its role, its
     | region, whether it works at all. None of it was logged — a role could be
     | widened, an account switched off, a password reset, and the system kept
     | no record of who did it. The trait redacts the password, the second
     | factor and the address; what is kept is that they changed.
     */
    use Auditable, HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'password', 'role_id', 'region_id', 'association_id', 'phone_encrypted', 'is_active',
        'two_factor_secret', 'two_factor_confirmed_at',
    ];

    protected $hidden = ['password', 'remember_token', 'phone_encrypted', 'two_factor_secret'];

    /** Roles that must pass a second factor before reaching a panel (T-38). */
    public const TWO_FACTOR_ROLES = ['admin', 'council', 'board_director', 'deputy_board_director'];

    /*
     | T-74 — how far down a scoped account sees, by the association's decision
     | of 13 October: «المناديب يقدر يطلع على بيانات قريته فقط، المشرفين على
     | بيانات منطقته».
     |
     | The scope itself was always the account's region and everything under
     | it. What was missing is this: nothing stopped a delegate being attached
     | to an area, and an area has villages under it, so one careless
     | assignment handed a delegate a whole area. The level is now part of the
     | account, refused on save rather than noticed later.
     */
    public const ROLE_REGION_LEVEL = [
        // Their lists of 13 October name three kinds of locality under an area
        // — مدينة, بلدة, قرية — and a delegate works in any of them. What the
        // rule forbids is a delegate holding an area or a governorate, which
        // would hand them every locality under it.
        'delegate' => ['city', 'town', 'village'],
        'area_supervisor' => ['area'],
        'deputy_area_supervisor' => ['area'],
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'phone_encrypted' => 'encrypted',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
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

    /*
     | Refused on the model, so the rule holds for the panel, an import and a
     | console command alike. An account with no region at all is left to the
     | region scope, which shows such an account nothing.
     */
    protected static function booted(): void
    {
        $check = function (self $user) {
            if (! $user->isDirty(['role_id', 'region_id'])) {
                return;
            }

            // The role the account is moving to, not the relation already
            // loaded on it: on a role change the latter is the old one.
            $roleKey = $user->role_id === $user->role?->getKey()
                ? $user->role?->key
                : Role::whereKey($user->role_id)->value('key');

            $levels = self::ROLE_REGION_LEVEL[$roleKey ?? ''] ?? null;

            if (! $levels || ! $user->region_id) {
                return;
            }

            $type = Region::whereKey($user->region_id)->value('type');

            if (! in_array($type, $levels, true)) {
                throw new \RuntimeException(__('sanabel.user.wrong_region_level', [
                    'role' => $user->role?->name_ar ?? '',
                    'level' => collect($levels)
                        ->map(fn (string $level) => __('sanabel.region_type.'.$level))
                        ->join('، ', ' أو '),
                    'given' => $type ? __('sanabel.region_type.'.$type) : '',
                ]));
            }
        };

        static::creating($check);
        static::updating($check);
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
        return app(PermissionService::class)->has($this, $permissionKey);
    }

    /**
     * The accounts that verify incoming money, so a donation that lands can
     * say so. Read from the permission rows, not a list of role names, so a
     * role granted verify_payment later is told without a code change.
     *
     * @return array<int,int>
     */
    public static function verifiers(): array
    {
        return static::query()
            ->where('is_active', true)
            ->whereHas('role.permissions', fn ($q) => $q->where('key', 'verify_payment'))
            ->pluck('id')
            ->all();
    }

    public function requiresTwoFactor(): bool
    {
        return in_array($this->role?->key, self::TWO_FACTOR_ROLES, true);
    }

    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->hasRole(
                'admin', 'case_officer', 'area_supervisor', 'delegate', 'council', 'finance', 'content_manager',
                // The structure of 5 October. Each one reaches the association
                // panel; what they see inside it is the matrix's business.
                'board_director', 'executive_director', 'deputy_executive_director',
                'deputy_area_supervisor', 'treasurer', 'oversight_director',
                'data_officer', 'data_supervisor', 'data_manager',
            ),
            'association' => $this->hasRole('association', 'admin'),
            'provider' => $this->hasRole('service_provider', 'admin'),
            default => false,
        };
    }
}
