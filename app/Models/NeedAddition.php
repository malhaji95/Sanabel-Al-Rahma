<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * T-289 — an amount the association decided to add to a household's monthly
 * need, for something they named.
 *
 * Deliberately not derived from anything: the medical cost on the file and
 * the documented debt raise the score and add nothing to the money, which is
 * the separation the association asked for. This is the other half, and it
 * only ever exists because somebody approved it.
 */
class NeedAddition extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    public const KINDS = ['medical', 'debt', 'other'];

    protected $fillable = [
        'beneficiary_id', 'kind', 'amount', 'currency', 'reason_ar',
        'starts_on', 'ends_on', 'approved_by', 'approved_at', 'created_by',
        // T-304 — تغطيةٌ بلا مبلغ: خدمةٌ تُقدَّم لا مالٌ يُدفع.
        'covered_by_health_path', 'coverage_note_ar',
    ];

    protected function casts(): array
    {
        return [
            'covered_by_health_path' => 'boolean',
            'amount' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The additions in force for the month being computed.
     *
     * Approved, started, and not yet ended. An unapproved row is somebody's
     * proposal and is worth no money.
     */
    /** T-304 — the earmarked payments that name this item. */
    public function coveringPayments(): HasMany
    {
        return $this->hasMany(SupportSource::class);
    }

    /**
     * T-304 — what somebody else paid towards this item, for one month.
     *
     * A payment answers the month it was recorded against and no other, which
     * is the rule every other payment in this system follows. The health path
     * is the exception that proves it: it carries no figure because it buys
     * no thing — it routes the family to a provider who treats them — so an
     * item answered that way is covered whole, however the month falls.
     */
    public function coveredAmount(?CarbonInterface $asOf = null): int
    {
        if ($this->covered_by_health_path) {
            return (int) $this->amount;
        }

        $month = ($asOf ? Carbon::instance($asOf->toDateTime()) : Carbon::now())->startOfMonth();

        $payments = $this->relationLoaded('coveringPayments')
            ? $this->coveringPayments
            : $this->coveringPayments()->get();

        return (int) $payments
            ->filter(fn (SupportSource $s) => $s->amount > 0
                && $s->coverage_month !== null
                && $s->coverage_month->isSameMonth($month))
            ->sum('amount');
    }

    /**
     * T-304 — «إضافة حالة واضحة توضح ما إذا كان المصروف غير مغطى أو مغطى
     * جزئيًا أو مغطى بالكامل».
     *
     * Read off the sums. Nobody types it, so nobody can type it wrong.
     */
    public function coverageState(?CarbonInterface $asOf = null): string
    {
        $covered = $this->coveredAmount($asOf);

        return match (true) {
            $covered <= 0 => 'none',
            $covered >= (int) $this->amount => 'full',
            default => 'partial',
        };
    }

    /**
     * T-304 — «منع تمويل المصروف نفسه مرتين».
     *
     * What this item still asks the association for: the amount it was
     * approved at, less what another body has already answered of it. Never
     * below nought — a body that overpaid has not earned the family a credit
     * against next month's bread.
     */
    public function netAmount(?CarbonInterface $asOf = null): int
    {
        return max(0, (int) $this->amount - $this->coveredAmount($asOf));
    }

    public function scopeInForce(Builder $query, CarbonInterface $asOf): Builder
    {
        $month = $asOf->copy()->startOfMonth();

        return $query
            ->whereNotNull('approved_at')
            ->whereDate('starts_on', '<=', $month->copy()->endOfMonth())
            ->where(fn (Builder $q) => $q
                ->whereNull('ends_on')
                ->orWhereDate('ends_on', '>=', $month));
    }
}
