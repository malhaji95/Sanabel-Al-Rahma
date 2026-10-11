<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
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

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'coverage_month' => 'date',
            'paid_on' => 'date',
        ];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    /** A payment the system may deduct: an amount, against a named month. */
    public function isPayment(): bool
    {
        return $this->amount > 0 && $this->coverage_month !== null;
    }
}
