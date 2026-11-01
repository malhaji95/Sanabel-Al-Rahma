<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DonationAllocation extends Model
{
    use Auditable, HasFactory;

    protected $fillable = ['donation_id', 'beneficiary_id', 'campaign_id', 'coverage_month', 'amount', 'currency'];

    // T-213 — `drawn_amount` is deliberately absent from $fillable: it is
    // moved by the service inside a locked transaction and by nothing else.
    protected $casts = ['amount' => 'integer', 'drawn_amount' => 'integer', 'coverage_month' => 'date'];

    /** T-213 — what is left of this allocation to pay a family with. */
    public function undrawn(): int
    {
        return max(0, (int) $this->amount - (int) $this->drawn_amount);
    }

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Rule — membership money can never be allocated to a family.
     */
    protected static function booted(): void
    {
        static::saving(function (self $m) {
            if (! $m->beneficiary_id) {
                return;
            }

            $fund = $m->donation?->fund ?? Fund::find(Donation::find($m->donation_id)?->fund_id);

            if ($fund && ! $fund->can_fund_families) {
                throw new \RuntimeException(
                    "Money from the '{$fund->key}' fund can never be allocated to a family."
                );
            }
        });
    }
}
