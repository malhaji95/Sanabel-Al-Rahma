<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One payment owed to one family, and every signature it collects. */
class Disbursement extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $guarded = ['id'];

    protected $casts = [
        'period' => 'date',
        'confirmed_at' => 'datetime',
        'approved_at' => 'datetime',
        'executed_at' => 'datetime',
        'reconciled_at' => 'datetime',
        'confirm_due_at' => 'datetime',
        'beneficiary_responded_at' => 'datetime',
    ];

    public const STATUSES = [
        'confirmed', 'in_order', 'approved', 'rejected', 'executed',
        // What the family themselves said about it.
        'received', 'disputed',
        'failed', 'reconciled',
    ];

    /** Transferred, and waiting on the household to say it arrived. */
    public function awaitsBeneficiary(): bool
    {
        return $this->status === 'executed';
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(DisbursementOrder::class, 'disbursement_order_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }
}
