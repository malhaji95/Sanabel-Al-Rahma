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

    /*
     | T-278 — «من اعتمدها», 9 November. The two names were stored from the
     | first migration and no screen ever read them, so the file could not say
     | who computed a study or who approved it.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
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

    /*
     | T-277 — how the money was arrived at, from the row itself.
     |
     | «نطلب شاشة تفصيلية داخل دراسة كل أسرة توضح كيف وصل النظام إلى مبلغ
     | الاحتياج والعجز والأولوية والتمويل, حتى يستطيع المسؤول مراجعة النتيجة
     | دون الرجوع للمبرمج» — 9 November.
     |
     | T-261 did this for the score. This is the other half, and it reads
     | the same way: everything comes out of `snapshot_json`, which the
     | engine froze on the day (rule 8), so a study from August explains
     | itself with August's reference values however they have been edited
     | since. Nothing here is recomputed and nothing is read from today's
     | settings.
     |
     | @return array{need:array,income:array,received:array,totals:array}
     */
    public function needBreakdown(): array
    {
        $snapshot = $this->snapshot_json ?? [];

        if ($snapshot === [] || ! isset($snapshot['rates'])) {
            return [];
        }

        // What the household's own people cost, class by class.
        $need = [];

        foreach ($snapshot['rates'] as $class => $rate) {
            $need[] = [
                'kind' => 'class',
                'key' => $class,
                'count' => (int) ($rate['count'] ?? 0),
                'unit' => (int) ($rate['amount'] ?? 0),
                'version' => $rate['version'] ?? null,
                'total' => (int) ($rate['amount'] ?? 0) * (int) ($rate['count'] ?? 0),
            ];
        }

        foreach ($snapshot['adjustments'] ?? [] as $key => $adjustment) {
            $need[] = [
                'kind' => 'adjustment',
                'key' => $key,
                'count' => 1,
                'unit' => (int) ($adjustment['amount'] ?? 0),
                'version' => $adjustment['version'] ?? null,
                'total' => (int) ($adjustment['amount'] ?? 0),
            ];
        }

        /*
         | The rent reference appears whether or not it was applied: a reader
         | asking why a renting family's need looks low is owed the line that
         | says the household owns its home, rather than a line that is not
         | there at all.
         */
        $need[] = [
            'kind' => 'rent',
            'key' => 'rent_reference',
            'count' => 1,
            'unit' => (int) ($snapshot['rent_reference']['amount'] ?? 0),
            'version' => $snapshot['rent_reference']['version'] ?? null,
            'total' => (bool) ($snapshot['is_renting'] ?? false)
                ? (int) ($snapshot['rent_reference']['amount'] ?? 0)
                : 0,
            'applied' => (bool) ($snapshot['is_renting'] ?? false),
        ];

        if ((int) ($snapshot['manual_adjustment'] ?? 0) !== 0) {
            $need[] = [
                'kind' => 'manual',
                'key' => 'manual_adjustment',
                'count' => 1,
                'unit' => (int) $snapshot['manual_adjustment'],
                'version' => null,
                'total' => (int) $snapshot['manual_adjustment'],
            ];
        }

        /*
         | The three places a stable income is read from. They are listed
         | apart because this is exactly where the association asked how a
         | figure is kept from being counted twice: a salary is read off the
         | person, livestock off its own record, and neither is copied into
         | an income row.
         */
        $income = [
            ['key' => 'income_rows', 'total' => (int) $this->stable_income
                - (int) ($snapshot['livestock_income'] ?? 0)
                - (int) ($snapshot['person_work_income'] ?? 0)],
            ['key' => 'person_work_income', 'total' => (int) ($snapshot['person_work_income'] ?? 0)],
            ['key' => 'livestock_income', 'total' => (int) ($snapshot['livestock_income'] ?? 0)],
        ];

        return [
            'need' => $need,
            'income' => $income,
            'external' => (int) ($snapshot['external_support'] ?? 0),
            'totals' => [
                'members' => (int) ($snapshot['members_total'] ?? 0),
                'adjustments' => (int) ($snapshot['adjustments_total'] ?? 0),
                'monthly_need' => (int) $this->monthly_need,
                'stable_income' => (int) $this->stable_income,
                'deficit' => max(0, (int) $this->monthly_need - (int) $this->stable_income),
                'gap' => (int) $this->gap,
            ],
            'computed_at' => $snapshot['computed_at'] ?? null,
        ];
    }

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
