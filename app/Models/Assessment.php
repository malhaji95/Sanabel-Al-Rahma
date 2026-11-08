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

    /*
     | T-261 — the number says how it was arrived at.
     |
     | «في مشكلة بموضوع الأوزان وكيف طريقة عملها وكيف مبنية, ما عارفين نهائيا,
     | وبدنا نعمل تجارب بس مو عارفين آلية عملها كيف تم الاحتساب», 8 November.
     |
     | The screen showed one number and nothing else, so the association was
     | asked to trust a figure they could not take apart. And the promise is
     | written ten lines above this: «the number can always be explained by
     | what it was computed from». Both halves of that explanation were
     | already on the row and neither was ever drawn — `factors_json` holds
     | each factor out of a hundred, and `snapshot_json['weights']` holds the
     | weights it was scored with.
     |
     | So nothing is recomputed here and nothing is read from the current
     | settings: an assessment from August explains itself with August's
     | weights, however they have been edited since, which is rule 8 doing
     | exactly what it was written for.
     */

    /** The seven factors in the order the formula states them, and what each is made of. */
    public const FACTOR_PARTS = [
        'F' => [],
        'M' => ['M_severity', 'M_economic_impact', 'M_care_burden', 'M_cost_burden'],
        'V' => ['V_dependents', 'V_single_caregiver', 'V_orphans', 'V_unsupported_elderly'],
        'H' => ['H_safety', 'H_overcrowding', 'H_services', 'H_eviction', 'H_rent_burden'],
        'U' => [],
        'D' => [],
        'B' => [],
    ];

    /**
     * @return array<int,array{key:string,value:float,weight:float,share:float,parts:array}>
     */
    public function scoreBreakdown(): array
    {
        $values = $this->factors_json ?? [];
        $weights = $this->snapshot_json['weights'] ?? [];

        // An assessment stored before either column was written explains
        // nothing rather than explaining it wrongly.
        if ($values === [] || $weights === []) {
            return [];
        }

        $rows = [];

        foreach (self::FACTOR_PARTS as $key => $parts) {
            $value = (float) ($values[$key] ?? 0);
            $weight = (float) ($weights[$key] ?? 0);

            $rows[] = [
                'key' => $key,
                'value' => round($value, 2),
                'weight' => $weight,
                'share' => round($weight * $value, 2),
                'parts' => array_values(array_map(
                    fn (string $part) => ['key' => $part, 'weight' => (float) ($weights[$part] ?? 0)],
                    $parts,
                )),
            ];
        }

        return $rows;
    }
}
