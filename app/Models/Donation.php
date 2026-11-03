<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Donation extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    /** Fields a reversal row is allowed to touch on an already-verified donation. */
    /**
     * Rule 5 guards the money, not the paperwork filed against it. Reversing
     * the status is one permitted change; attaching the record of the transfer
     * the association made onward is the other, because it adds evidence and
     * moves no figure. Everything else on a verified donation is frozen.
     */
    private const REVERSAL_SAFE_FIELDS = ['status', 'updated_at'];

    private const EVIDENCE_FIELDS = ['transfer_record_media_id', 'updated_at'];

    protected $fillable = [
        'reference_no',
        'donor_id', 'route', 'designation', 'amount', 'currency', 'transaction_ref', 'receipt_media_id',
        'status', 'verified_by', 'verified_at', 'reject_reason', 'fund_id', 'basket_id',
        'transfer_record_media_id',
        'reversal_of_id', 'created_by',
    ];

    protected $casts = ['verified_at' => 'datetime', 'amount' => 'integer'];

    /**
     * T-203 — «ضروري كل تبرع ياخذ رقم تذكرة أو طلب», 1 November.
     *
     * Issued here rather than by whoever writes the row, so that no path into
     * the table can leave one without a number: the basket, the general gift,
     * the guest gift, a reversal, a seeder and a test all pass through this.
     *
     * `transaction_ref` is the bank's number and stays the bank's. This is
     * the platform's, and it is the one to quote in a message or on the
     * telephone: the association can read it back before the donor has sent
     * anything, and a donor who mistyped the bank's reference still has one.
     *
     * The year and a running count inside it, as a payment order (DO-26-…)
     * and a complaint (CMP-26-…) already are. Soft-deleted rows are counted,
     * so a number is never handed out twice.
     */
    public static function nextReference(): string
    {
        $year = now()->format('y');
        $count = self::withTrashed()->whereYear('created_at', now()->year)->count() + 1;

        return sprintf('DN-%s-%05d', $year, $count);
    }

    /**
     * The record of the transfer the association made onward, uploaded by
     * finance and shown to the donor. Not the donor's own deposit slip.
     */
    public function transferRecord(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'transfer_record_media_id');
    }

    /**
     * T-181 — the donor's own deposit slip, the one they uploaded themselves.
     *
     * The other way round from `transferRecord()` above, and the two are
     * easily confused: this is the paper the donor hands in, that is the paper
     * the association hands back. A donor whose receipt was refused is shown
     * their own, beside the reason, so they can see what was read.
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'receipt_media_id');
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function basket(): BelongsTo
    {
        return $this->belongsTo(Basket::class);
    }

    /** T-241 — the cards given on this transfer, where it is a gift. */
    public function gifts(): HasMany
    {
        return $this->hasMany(Gift::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(DonationAllocation::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversal()
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }

    /**
     * Rule 5 — a verified donation is never edited. A correction creates a linked
     * reversal row instead. Only the transition to `reversed` is allowed through.
     */
    protected static function booted(): void
    {
        // T-203 — no path into this table may leave a donation unnumbered.
        static::creating(function (self $m) {
            $m->reference_no ??= self::nextReference();
        });

        static::updating(function (self $m) {
            $wasVerified = $m->getOriginal('status') === 'verified';

            if (! $wasVerified) {
                return;
            }

            $touched = array_keys($m->getDirty());

            // Attaching evidence changes no amount, so it is allowed on its own.
            if (array_diff($touched, self::EVIDENCE_FIELDS) === []) {
                return;
            }

            $illegal = array_diff($touched, self::REVERSAL_SAFE_FIELDS);
            $reversingOnly = $touched === ['status'] && $m->status === 'reversed';

            if ($illegal || ! $reversingOnly) {
                throw new \RuntimeException(
                    'A verified donation cannot be updated. Create a reversal row instead.'
                );
            }
        });
    }
}
