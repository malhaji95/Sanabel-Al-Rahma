<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use App\Services\CoverageService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = [
        'beneficiary_id', 'title_ar', 'body_ar', 'goal_amount',
        'currency', 'wallet_encrypted', 'surplus_policy_text_ar', 'is_published', 'status',
        'fund_id', 'created_by',
    ];

    protected $hidden = ['wallet_encrypted'];

    protected function casts(): array
    {
        return [
            'wallet_encrypted' => 'encrypted',
            'is_published' => 'boolean',
            'goal_amount' => 'integer',
        ];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function basketItems(): HasMany
    {
        return $this->hasMany(BasketItem::class);
    }

    /**
     * Raised and held are derived from the allocations and the live baskets,
     * never stored. A campaign that carried its own totals could disagree with
     * the money underneath it, and only one of the two could be right.
     */
    public function collectedAmount(): int
    {
        return app(CoverageService::class)->campaignConfirmed($this);
    }

    public function reservedAmount(): int
    {
        return app(CoverageService::class)->campaignReserved($this);
    }

    public function progressPercent(?int $collected = null): int
    {
        return $this->goal_amount > 0
            ? (int) min(100, floor(100 * ($collected ?? $this->collectedAmount()) / $this->goal_amount))
            : 0;
    }

    public function acceptsPledges(?int $collected = null): bool
    {
        return $this->status === 'active'
            && (($collected ?? $this->collectedAmount()) + $this->reservedAmount()) < $this->goal_amount;
    }

    /**
     * Reaching the goal closes funding; it never completes the campaign. The
     * money still has to be moved and its delivery proved, which is what
     * `awaiting_execution` and then `completed` stand for.
     */
    public function closeFundingIfMet(?int $collected = null): void
    {
        $raised = $collected ?? $this->collectedAmount();

        if ($this->status === 'active' && $raised >= $this->goal_amount) {
            $this->forceFill(['status' => 'funded'])->save();

            return;
        }

        // A reversal can take a funded campaign back below its goal.
        if ($this->status === 'funded' && $raised < $this->goal_amount) {
            $this->forceFill(['status' => 'active'])->save();
        }
    }

    /** surplus_policy_text_ar is mandatory before publishing (rule 7). */
    protected static function booted(): void
    {
        static::saving(function (self $m) {
            if ($m->is_published && blank($m->surplus_policy_text_ar)) {
                throw new \RuntimeException(__('sanabel.campaigns.surplus_policy_required'));
            }
        });
    }
}
