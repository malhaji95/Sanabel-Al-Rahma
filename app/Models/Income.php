<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use App\Services\MonthlyAverage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Income extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = ['beneficiary_id', 'source_type', 'amount', 'currency', 'is_stable', 'recurrence', 'times_per_year', 'created_by'];

    protected $casts = ['is_stable' => 'boolean', 'amount' => 'integer', 'times_per_year' => 'integer'];

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    /*
     | T-287 — what this row puts into the household's month.
     |
     | Three answers, which are the four the association named:
     |
     |   steady          — the amount, every month.
     |   not steady, but
     |   counted on      — the amount times how often a year, over twelve.
     |   not steady and
     |   not counted on  — nothing. It is on the file and deducted from no
     |                     month, which is where a one-off gift and work that
     |                     may not come both belong.
     |
     | The division is done here rather than in the researcher's head, which
     | is the whole point: a sum nobody can see is a sum nobody can check.
     */
    public function monthlyContribution(): int
    {
        // T-303 — one rule, in one place, for the four kinds of money that
        // now answer to it.
        return MonthlyAverage::of(
            (bool) $this->is_stable,
            (int) $this->amount,
            $this->times_per_year,
        );
    }
}
