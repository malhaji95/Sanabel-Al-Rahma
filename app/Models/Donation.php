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
        'donor_id', 'route', 'designation', 'amount', 'currency', 'transaction_ref', 'receipt_media_id',
        'status', 'verified_by', 'verified_at', 'reject_reason', 'fund_id', 'basket_id',
        'transfer_record_media_id',
        'reversal_of_id', 'created_by',
    ];

    protected $casts = ['verified_at' => 'datetime', 'amount' => 'integer'];

    /**
     * The record of the transfer the association made onward, uploaded by
     * finance and shown to the donor. Not the donor's own deposit slip.
     */
    public function transferRecord(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'transfer_record_media_id');
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
