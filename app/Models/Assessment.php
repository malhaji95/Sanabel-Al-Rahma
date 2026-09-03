<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Assessment extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = [
        'beneficiary_id', 'visit_id', 'monthly_need', 'stable_income', 'gap', 'currency',
        'base_score', 'factors_json', 'snapshot_json', 'valid_until', 'status',
        'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'factors_json' => 'array',
        'snapshot_json' => 'array',
        'valid_until' => 'date',
        'approved_at' => 'datetime',
        'monthly_need' => 'integer',
        'stable_income' => 'integer',
        'gap' => 'integer',
        'base_score' => 'float',
    ];

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(Override::class);
    }

    /** The score actually used for ranking: an approved, unexpired override wins. */
    public function effectiveScore(): float
    {
        $live = fn ($override) => $override->approved_by !== null
            && ($override->expires_at === null || $override->expires_at->isFuture());

        // As in Beneficiary::currentAssessment(), use the relation when a list
        // has already loaded it rather than querying once per assessment.
        if ($this->relationLoaded('overrides')) {
            $override = $this->overrides->filter($live)->sortByDesc('id')->first();

            return (float) ($override?->new_score ?? $this->base_score);
        }

        $override = $this->overrides()
            ->whereNotNull('approved_by')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('id')
            ->first();

        return (float) ($override?->new_score ?? $this->base_score);
    }
}
