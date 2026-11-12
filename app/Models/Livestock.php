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
 * Animals the family keeps, and whether they bring anything in.
 *
 * Approved 11 October: where the net income is real, steady and repeating it
 * is stable income and the engine reads it; where it is seasonal or
 * intermittent it is recorded and deducted from nothing. `monthly_income` is
 * the net figure — what is left after feed and care — and `is_stable` is what
 * decides whether the engine reads it.
 */
class Livestock extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $table = 'livestock';

    protected $guarded = ['id'];

    public const KINDS = ['cows', 'sheep', 'other'];

    /** لا، للاستخدام الأسري فقط | نعم، دخل جزئي | نعم، مصدر دخل رئيسي */
    public const INCOME_KINDS = ['family_use', 'partial_income', 'main_income'];

    protected function casts(): array
    {
        return [
            'head_count' => 'integer',
            'monthly_income' => 'integer',
            'is_stable' => 'boolean',
        ];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    /**
     * Income the engine may deduct. Null `is_stable` means nobody has answered
     * the question yet, which is not the same as "no", so nothing is deducted
     * until somebody does.
     */
    public function countsAsIncome(): bool
    {
        return $this->monthlyContribution() > 0;
    }

    /**
     * T-303 — what the animals put into a month.
     *
     * The amount was counted only where `is_stable` was true, and that was a
     * switch on a form: a researcher who never touched it left a steady
     * income at nought with nothing on the screen to say so. It is a question
     * now, and a seasonal income is averaged rather than dropped — a flock
     * sold once a year is money the household lives on all twelve months.
     */
    public function monthlyContribution(): int
    {
        return MonthlyAverage::of(
            $this->is_stable,
            (int) $this->monthly_income,
            $this->times_per_year,
        );
    }
}
