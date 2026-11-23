<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * T-323 — one month's books, open or closed.
 *
 * Audited: closing a month and reopening one are both decisions about money,
 * and the second is the one an auditor looks for.
 */
class FinancialPeriod extends Model
{
    use Auditable, HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    /**
     * A month is closed while it has a closing and no later reopening.
     *
     * Reading it from the two stamps rather than from a status column means
     * the row cannot say «open» while carrying a closing nobody undid.
     */
    public function isClosed(): bool
    {
        if (! $this->closed_at) {
            return false;
        }

        return ! $this->reopened_at || $this->reopened_at->lt($this->closed_at);
    }

    /** Whether the month containing this date is closed. */
    public static function isClosedFor(Carbon $date): bool
    {
        $row = static::query()
            ->whereDate('period', $date->copy()->startOfMonth())
            ->first();

        return $row?->isClosed() ?? false;
    }
}
