<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * T-213 — which earmarked donation paid for which payment, and how much of it.
 *
 * Written so that a payment can give its money back: a line that is rejected
 * or fails has to release what it drew, and releasing it means knowing where
 * it came from. It is also the only honest answer to «who paid for this
 * family's November?», which the two chains could not answer between them.
 */
class DisbursementFunding extends Model
{
    use Auditable, HasFactory;

    protected $fillable = ['disbursement_id', 'donation_allocation_id', 'amount', 'currency'];

    protected $casts = ['amount' => 'integer'];

    public function disbursement(): BelongsTo
    {
        return $this->belongsTo(Disbursement::class);
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(DonationAllocation::class, 'donation_allocation_id');
    }
}
