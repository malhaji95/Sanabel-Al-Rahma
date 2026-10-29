<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Donor extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = [
        'user_id', 'name_ar', 'phone_encrypted', 'phone_hash', 'email', 'wallet_encrypted',
        'donations_count', 'badge', 'created_by',
    ];

    protected $hidden = ['phone_encrypted', 'wallet_encrypted'];

    /**
     * T-108 — the lookup beside the encrypted number, so a guest who gives
     * again is the same donor. Keyed with the application key, exactly as
     * Beneficiary::hashNationalId is: it matches, and it gives nothing back.
     */
    public static function hashPhone(string $phone): string
    {
        $normalised = preg_replace('/\D+/', '', $phone) ?: $phone;

        return hash_hmac('sha256', $normalised, (string) config('app.key'));
    }

    protected function casts(): array
    {
        return [
            'phone_encrypted' => 'encrypted',
            'wallet_encrypted' => 'encrypted',
            'donations_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class);
    }

    public function baskets(): HasMany
    {
        return $this->hasMany(Basket::class);
    }

    /**
     * T-180 — the families this donor kept, to come back to.
     *
     * Paying for one does not take it off: «وعند السداد لهم تبقى في المفضلة
     * للعودة لها متى ما شاء». Only the donor removes a family from here.
     */
    public function favourites(): BelongsToMany
    {
        return $this->belongsToMany(Beneficiary::class, 'donor_favourites')
            ->withTimestamps()
            ->orderByPivot('created_at', 'desc');
    }

    /** Badges by verified donation count — silver >= 3, gold >= 10 (docs/07). */
    public function refreshBadge(): void
    {
        $count = $this->donations()->where('status', 'verified')->whereNull('reversal_of_id')->count();
        $silver = (int) Setting::value('badge_silver_min', 3);
        $gold = (int) Setting::value('badge_gold_min', 10);

        $this->forceFill([
            'donations_count' => $count,
            'badge' => match (true) {
                $count >= $gold => 'gold',
                $count >= $silver => 'silver',
                default => 'none',
            },
        ])->save();
    }
}
