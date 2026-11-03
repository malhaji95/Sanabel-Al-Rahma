<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * T-241 — one card, in somebody's name, against one transfer.
 *
 * The recipient never signed up for anything, so their number is encrypted
 * at rest and hidden from serialisation like the file-holder's own
 * (rule 10). What the card itself shows is a name, and the amount only
 * where the donor said to show it.
 */
class Gift extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $guarded = ['id'];

    protected $casts = [
        'show_amount' => 'boolean',
        'amount' => 'integer',
        'send_at' => 'datetime',
        'issued_at' => 'datetime',
        'recipient_phone_encrypted' => 'encrypted',
    ];

    protected $hidden = ['recipient_phone_encrypted'];

    /**
     * Rule 10 — the number never reaches an audit entry in clear either.
     *
     * Added to the trait's list rather than replacing it, the way T-230 did:
     * a column the shared list grows tomorrow is redacted here too.
     */
    protected function auditRedactedKeys(): array
    {
        return array_merge($this->auditRedacted, ['recipient_phone_encrypted']);
    }

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(GiftCard::class, 'gift_card_id');
    }

    /** Null is a general gift; a campaign is a gift aimed at a project. */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** Whether this card is due to go out: immediately, or at its hour. */
    public function isDue(): bool
    {
        return $this->status === 'pending'
            && (blank($this->send_at) || $this->send_at->isPast());
    }

    /** The number as it would be dialled: country key then the digits. */
    public function dialNumber(): string
    {
        $key = preg_replace('/\D/', '', (string) $this->recipient_country_code);
        $number = preg_replace('/\D/', '', (string) $this->recipient_phone_encrypted);

        return $key.ltrim($number, '0');
    }

    protected static function booted(): void
    {
        static::creating(function (self $gift) {
            // The card is read by its token, so the address of one card
            // cannot be guessed from the address of another.
            $gift->token ??= Str::lower(Str::random(32));
        });
    }
}
