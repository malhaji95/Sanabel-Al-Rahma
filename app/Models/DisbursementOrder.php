<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A batch of payments that leaves the association under one approval. */
class DisbursementOrder extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $guarded = ['id'];

    protected $casts = [
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'settled_at' => 'datetime',
        'approved_duly' => 'boolean',
    ];

    public const STATUSES = ['draft', 'pending_approval', 'approved', 'executing', 'settled', 'cancelled'];

    /** Once approved the order is frozen: no line joins it and none leaves. */
    public const FROZEN = ['approved', 'executing', 'settled', 'cancelled'];

    public function disbursements(): HasMany
    {
        return $this->hasMany(Disbursement::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isFrozen(): bool
    {
        return in_array($this->status, self::FROZEN, true);
    }

    public function total(): int
    {
        return (int) $this->disbursements()->whereNot('status', 'rejected')->sum('amount');
    }
}
