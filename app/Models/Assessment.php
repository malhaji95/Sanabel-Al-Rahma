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
    /**
     * The score, which is the computed one and no other.
     *
     * 16 Oct 2026 — «بدنا نتأكد انه ما في تعديل للدرجة النهائية يدوي. اذا كان
     * احد المؤثرات فيه خطأ يتم تعديل البيانات او العامل المؤثر، ولكن الدرجة لا
     * تتعدل يدوي».
     *
     * There used to be a live override here: a number a person could set,
     * which then stood in for everything the assessment worked out. It is
     * gone. A score that can be typed over is not a ranking, it is a ranking
     * plus an opinion, and the family below the one that was moved up has no
     * way to know which of the two they lost to.
     *
     * The correction for a wrong score is the wrong input: the income, the
     * rent, the health band, the weight. Those are recorded, audited, and
     * carried on the assessment's own snapshot (rule 8), so the number can
     * always be explained by what it was computed from.
     *
     * The table and its rows are kept — nothing is ever hard-deleted, and the
     * overrides taken before this decision stay readable as the record of
     * what was done. They no longer move anybody.
     */
    public function effectiveScore(): float
    {
        return (float) $this->base_score;
    }
}
