<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use App\Services\MonthlyAverage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One payment another association or body made towards this family's need.
 *
 * The association decided on 11 October that such a payment is treated exactly
 * as a partial donation: it comes off the remaining need for the month it
 * answers, and off no other month. It is coverage, never income, so it stays
 * out of stable income and out of the score; and a one-off payment does not
 * travel to the next month.
 *
 * `kind` is what the delegate was told about the arrangement. It does not
 * create a payment: only a row with an amount and a coverage month deducts
 * anything, and a recurring body therefore gets one row per month it pays.
 */
class SupportSource extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    /** يدفع كل شهر | دفعة لمرة واحدة */
    public const KINDS = ['recurring', 'one_off'];

    /*
     | T-280 — what the payment answers.
     |
     | `living` goes against the family's monthly need, the way a partial
     | donation does. `earmarked` is a payment for one named thing — a heating
     | bill, a course fee, a course of medicine — and the family's monthly
     | living need did not shrink because somebody paid it. It is recorded and
     | shown and deducted from nothing.
     */
    public const PURPOSES = ['living', 'earmarked'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'coverage_month' => 'date',
            'is_confirmed' => 'boolean',
            'paid_on' => 'date',
        ];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    /**
     * T-304 — the approved item this payment answers, where it answers one.
     *
     * An earmarked payment used to be deducted from nothing at all: recorded,
     * shown, and attached to no expense. Naming the item is what stops the
     * association collecting for a treatment somebody else has paid for.
     */
    public function needAddition(): BelongsTo
    {
        return $this->belongsTo(NeedAddition::class);
    }

    /**
     * A payment the system may deduct from a month: an amount, against a
     * named month, and not a standing arrangement.
     *
     * T-303 — «منع خصم المساعدة نفسها مرتين، مرة من الدخل ومرة من المتبقي
     * للتمويل», 12 November. A recurring arrangement is income now, so it
     * must not also be coverage: the kind decides which of the two a row is,
     * and the two are exclusive by construction.
     */
    public function isPayment(): bool
    {
        return $this->kind !== 'recurring'
            && $this->amount > 0
            && $this->coverage_month !== null
            && $this->purpose !== 'earmarked';
    }

    /**
     * T-303 — what a standing arrangement puts into a month.
     *
     * «مساعدات الجيران الشهرية ... مساعدات الأقارب الشهرية ... مساعدة زوج
     * البنت ... مساعدات الجمعيات الشهرية» — all of them recorded here, and
     * all of them reaching nothing: the table was built for a payment against
     * a named month, so an arrangement with no month deducted from nothing
     * and was not income either.
     *
     * Three conditions, each of them theirs. It must be recurring, because a
     * one-off payment is coverage and counting it here would count it twice.
     * It must be confirmed as arriving, because «عدم احتساب الوعود غير
     * المؤكدة كدخل». And it must be for the family's living, because money
     * given for one named thing did not raise what the household has to live
     * on (T-280).
     */
    public function monthlyContribution(): int
    {
        if ($this->kind !== 'recurring' || $this->is_confirmed !== true || $this->isEarmarked()) {
            return 0;
        }

        // A recurring arrangement with no count stated arrives every month,
        // which is what «يدفع كل شهر» says on the screen the delegate reads.
        return MonthlyAverage::of(false, (int) $this->amount, $this->times_per_year ?? 12);
    }

    /** Recorded, shown, and never deducted from the monthly need. */
    public function isEarmarked(): bool
    {
        return $this->purpose === 'earmarked';
    }

    /*
     | T-280, corrected after the full suite: the column is NOT NULL with a
     | default of `living`, but a default only answers for a column that is
     | left out. A caller that sends the key with nothing in it — a cleared
     | select, a row written by an importer — sends an explicit null, and the
     | insert is refused with a 500 in the researcher's face.
     |
     | So a purpose that is not stated is living support here too, which is
     | the same sentence the column's default makes. The constraint still
     | holds the rule; this only stops a null from ever reaching it.
     */
    public function setPurposeAttribute(?string $value): void
    {
        $this->attributes['purpose'] = in_array($value, self::PURPOSES, true)
            ? $value
            : 'living';
    }
}
