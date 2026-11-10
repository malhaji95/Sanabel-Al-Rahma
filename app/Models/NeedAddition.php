<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

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
    ];

    protected function casts(): array
    {
        return [
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
