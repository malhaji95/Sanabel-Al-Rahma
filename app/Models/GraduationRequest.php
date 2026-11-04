<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * T-249 — a study, a recommendation and a decision, on their way to saying
 * that a family no longer needs help.
 */
class GraduationRequest extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = [
        'beneficiary_id', 'monthly_income', 'currency', 'stable_since', 'study_note_ar',
        'need_snapshot', 'status', 'requested_by', 'reviewed_by', 'reviewed_at',
        'recommendation', 'recommendation_note_ar', 'decided_by', 'decided_at',
        'decision_note_ar', 'created_by',
    ];

    protected $casts = [
        'monthly_income' => 'integer',
        'need_snapshot' => 'integer',
        'stable_since' => 'date',
        'reviewed_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** Whether the study's own figures clear the association's bar. */
    public function incomeIsSufficient(): bool
    {
        return $this->monthly_income >= $this->need_snapshot;
    }
}
