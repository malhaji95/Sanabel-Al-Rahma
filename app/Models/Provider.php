<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToRegion;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Provider extends Model
{
    use Auditable, BelongsToRegion, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = [
        'user_id', 'name_ar', 'type', 'specialty_ar', 'region_id', 'discount_type',
        'discount_value', 'valid_until', 'status', 'created_by',
        'case_quota', 'quota_period', 'case_value', 'quota_used',
    ];

    protected $casts = [
        'valid_until' => 'date',
        'discount_value' => 'integer',
        'case_quota' => 'integer',
        'case_value' => 'integer',
        'quota_used' => 'integer',
    ];

    /**
     * How many cases this provider has taken against its quota.
     *
     * A monthly quota counts the cards issued this month, so it renews without
     * anything having to reset it — a reset job that fails is a quota that
     * quietly never comes back. A fixed quota draws down a stored counter,
     * because there is no month to count within.
     */
    public function quotaUsed(): int
    {
        if ($this->case_quota === null) {
            return 0;
        }

        return $this->quota_period === 'fixed'
            ? $this->quota_used
            : $this->referrals()->where('issued_at', '>=', now()->startOfMonth())->count();
    }

    /** What is left before the agreed number is passed; null when none was agreed. */
    public function quotaRemaining(): ?int
    {
        return $this->case_quota === null ? null : $this->case_quota - $this->quotaUsed();
    }

    /**
     * The association chose on 30 Sep that passing the quota warns rather than
     * blocks, so this reports the overage instead of forbidding the card.
     */
    public function quotaExceeded(): bool
    {
        $remaining = $this->quotaRemaining();

        return $remaining !== null && $remaining <= 0;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class);
    }
}
