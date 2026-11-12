<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One creditor the family owes, from the social survey.
 *
 * T-302 — «ربط عامل المديونية تلقائيًا بالديون التي تم التحقق منها واعتماد
 * أهليتها», 12 November.
 *
 * It used to be record only: the score read a figure typed into a box on the
 * file, and these rows sat beside it saying something that need not agree.
 * The association has now decided what makes a debt count, and it is read off
 * the row itself, in three states.
 *
 *   recorded — the row exists;
 *   verified — somebody named how they checked it, and when;
 *   eligible — verified, and for a basic need.
 *
 * Only the third reaches the score. And counting a debt is still not a
 * decision to pay it.
 */
class Debt extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $guarded = ['id'];

    protected $hidden = ['creditor_phone_encrypted'];

    protected function casts(): array
    {
        return [
            // Somebody else's telephone number is still a telephone number.
            'creditor_phone_encrypted' => 'encrypted',
            'amount' => 'integer',
            'months' => 'integer',
            'has_due_date' => 'boolean',
            'due_date' => 'date',
            'is_essential' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /** The means the association accepts as a check on a debt. */
    public const VERIFICATION_METHODS = [
        'document', 'creditor_statement', 'field_visit', 'witness',
    ];

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** Somebody checked it, and said by what means. */
    public function isVerified(): bool
    {
        return $this->verified_at !== null && filled($this->verification_method);
    }

    /**
     * The one state the score reads. An unanswered «أساسي؟» is not a «نعم»:
     * a debt nobody has placed scores nothing rather than scoring on a guess.
     */
    public function isEligible(): bool
    {
        return $this->isVerified() && $this->is_essential === true && $this->amount > 0;
    }

    /** @param  Builder<Debt>  $query */
    public function scopeEligible($query)
    {
        return $query->whereNotNull('verified_at')
            ->whereNotNull('verification_method')
            ->where('is_essential', true)
            ->where('amount', '>', 0);
    }
}
