<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sponsorship extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = [
        'donor_id', 'beneficiary_id', 'amount', 'currency', 'start_date', 'end_date', 'status', 'created_by',
        // T-238 — which member of the household this sponsorship is for.
        'household_member_id',
    ];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'amount' => 'integer'];

    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    /**
     * T-238 — the member this sponsorship is for, where it is for one.
     *
     * Null means the sponsorship is of the household as a whole, which is
     * what every row written before 3 November means.
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(HouseholdMember::class, 'household_member_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(SponsorshipInstallment::class);
    }

    /** Start and end date are both required (rule 8). */
    protected static function booted(): void
    {
        static::saving(function (self $m) {
            if (blank($m->start_date) || blank($m->end_date)) {
                throw new \RuntimeException(__('sanabel.sponsorships.end_date_required'));
            }

            /*
             | T-238 — a sponsorship may only name a member of the family it
             | sponsors. The two columns are filled by two different screens
             | and nothing else compares them, so the wrong pair would read
             | as a perfectly ordinary row: the family's coverage would count
             | money that was pledged for a child in another house.
             */
            if (filled($m->household_member_id)) {
                $belongs = HouseholdMember::withTrashed()
                    ->whereKey($m->household_member_id)
                    ->where('beneficiary_id', $m->beneficiary_id)
                    ->exists();

                if (! $belongs) {
                    throw new \RuntimeException(__('sanabel.sponsorships.member_not_in_household'));
                }
            }
        });
    }
}
