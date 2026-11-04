<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\GraduationRequest;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * T-249 — how a family graduates, in the association's own words of
 * 4 November.
 *
 * Three hands, in order and never the same one twice: the field delegate
 * studies the household's income afresh, the area supervisor reads the
 * study and recommends, the executive director decides. Nothing here lets
 * a step be skipped and nothing lets one person hold two of them — which
 * is the whole reason the policy names three people rather than one.
 *
 * And two bars the decision has to clear, both of them theirs:
 *
 *   · «دخل كافٍ … يغطي احتياجاتها الأساسية، مع مراعاة عدد أفرادها وظروفها
 *     والتزاماتها الضرورية» — so the income is weighed against the
 *     household's own computed monthly need, which is where family size,
 *     circumstances and obligations already live. Not a figure we invent
 *     here, and not a number typed by whoever is deciding.
 *   · «استقرار الدخل مثبت لمدة ثلاثة أشهر على الأقل، وتكون المدة قابلة
 *     للتعديل من الإعدادات» — a setting, read at the moment of the
 *     decision.
 *
 * The bars are checked twice, at the study and again at the decision: a
 * family's need moves with its own figures and with the reference amounts,
 * so a study that cleared the bar in March may not clear it in June, and
 * the decision is what matters.
 *
 * Their last sentence is the one this whole service exists for: «مجرد
 * تسليم مشروع تنموي أو إغلاق معاملة ما بيعني أن الأسرة تخرجت من الفقر».
 * Closing a file is `CaseService::close()` and says nothing about a
 * family; this is the only road to «متخرج» there is.
 */
class GraduationService
{
    public function __construct(
        private readonly CoverageService $coverage,
        private readonly CaseService $cases,
        private readonly NotificationService $notifications,
    ) {}

    /** How long the income must have been steady. Data, so they move it. */
    public static function stableMonths(): int
    {
        return (int) Setting::value(
            'graduation_stable_months',
            config('sanabel.setting_defaults.graduation_stable_months')
        );
    }

    /** The delegate's fresh study. */
    public function study(
        Beneficiary $case,
        User $delegate,
        int $monthlyIncome,
        Carbon $stableSince,
        string $studyNoteAr,
    ): GraduationRequest {
        if (! in_array($case->status, Beneficiary::MAY_RECEIVE_MONEY, true)) {
            throw new \RuntimeException(__('sanabel.graduation.not_a_live_file'));
        }

        if (blank(trim($studyNoteAr))) {
            throw new \RuntimeException(__('sanabel.graduation.study_required'));
        }

        if ($case->graduationRequests()->whereIn('status', ['pending_supervisor', 'pending_director'])->exists()) {
            throw new \RuntimeException(__('sanabel.graduation.already_pending'));
        }

        $need = $this->coverage->needAmount($case);

        $this->guardBars($monthlyIncome, $need, $stableSince);

        return GraduationRequest::create([
            'beneficiary_id' => $case->getKey(),
            'monthly_income' => $monthlyIncome,
            'currency' => config('sanabel.currency'),
            'stable_since' => $stableSince->toDateString(),
            'study_note_ar' => trim($studyNoteAr),
            'need_snapshot' => $need,
            'status' => 'pending_supervisor',
            'requested_by' => $delegate->getKey(),
        ]);
    }

    /** The area supervisor's reading of it. */
    public function recommend(
        GraduationRequest $request,
        User $supervisor,
        string $recommendation,
        string $noteAr,
    ): GraduationRequest {
        if ($request->status !== 'pending_supervisor') {
            throw new \RuntimeException(__('sanabel.graduation.not_awaiting_recommendation'));
        }

        if (! in_array($recommendation, ['support', 'object'], true)) {
            throw new \RuntimeException(__('sanabel.graduation.recommendation_required'));
        }

        if (blank(trim($noteAr))) {
            throw new \RuntimeException(__('sanabel.graduation.recommendation_note_required'));
        }

        // The hand that studied is not the hand that reviews it.
        if ($request->requested_by && (int) $request->requested_by === (int) $supervisor->getKey()) {
            throw new \RuntimeException(__('sanabel.graduation.self_review_blocked'));
        }

        $request->forceFill([
            'reviewed_by' => $supervisor->getKey(),
            'reviewed_at' => now(),
            'recommendation' => $recommendation,
            'recommendation_note_ar' => trim($noteAr),
            // An objection does not end it: the director still decides, and
            // the objection is in front of them when they do.
            'status' => 'pending_director',
        ])->save();

        return $request->refresh();
    }

    /** The executive director's decision, and the only road to «متخرج». */
    public function approve(GraduationRequest $request, User $director): GraduationRequest
    {
        $this->guardDecision($request, $director);

        $case = $request->beneficiary;

        // Checked again, against today's need and today's setting: a study
        // that cleared the bar in March may not clear it in June.
        $this->guardBars(
            $request->monthly_income,
            $this->coverage->needAmount($case),
            $request->stable_since,
        );

        return DB::transaction(function () use ($request, $director, $case) {
            $request->forceFill([
                'status' => 'approved',
                'decided_by' => $director->getKey(),
                'decided_at' => now(),
            ])->save();

            $this->cases->graduate($case, __('sanabel.graduation.reason_on_file', [
                'income' => number_format($request->monthly_income),
                'since' => $request->stable_since->toDateString(),
            ]));

            $this->notifications->send(
                $request->requested_by,
                'graduation_decided',
                ['file_number' => $case->file_number],
            );

            return $request->refresh();
        });
    }

    public function refuse(GraduationRequest $request, User $director, string $noteAr): GraduationRequest
    {
        $this->guardDecision($request, $director);

        if (blank(trim($noteAr))) {
            throw new \RuntimeException(__('sanabel.graduation.refusal_note_required'));
        }

        $request->forceFill([
            'status' => 'refused',
            'decided_by' => $director->getKey(),
            'decided_at' => now(),
            'decision_note_ar' => trim($noteAr),
        ])->save();

        $this->notifications->send(
            $request->requested_by,
            'graduation_decided',
            ['file_number' => $request->beneficiary?->file_number],
        );

        return $request->refresh();
    }

    /** The two bars: a sufficient income, steady for long enough. */
    private function guardBars(int $monthlyIncome, int $need, Carbon $stableSince): void
    {
        if ($monthlyIncome < $need) {
            throw new \RuntimeException(__('sanabel.graduation.income_below_need', [
                'income' => number_format($monthlyIncome),
                'need' => number_format($need),
            ]));
        }

        $months = static::stableMonths();

        if ($stableSince->copy()->addMonths($months)->isFuture()) {
            throw new \RuntimeException(__('sanabel.graduation.not_stable_long_enough', [
                'months' => $months,
            ]));
        }
    }

    private function guardDecision(GraduationRequest $request, User $director): void
    {
        if ($request->status !== 'pending_director') {
            throw new \RuntimeException(__('sanabel.graduation.not_awaiting_decision'));
        }

        // Three hands, and never the same one twice.
        foreach ([$request->requested_by, $request->reviewed_by] as $earlier) {
            if ($earlier && (int) $earlier === (int) $director->getKey()) {
                throw new \RuntimeException(__('sanabel.graduation.self_decision_blocked'));
            }
        }
    }
}
