<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\Beneficiary;
use App\Models\Override;
use App\Models\Setting;
use App\Models\Visit;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class AssessmentService
{
    public function __construct(
        private readonly NeedEngine $needEngine,
        private readonly ScoreService $scoreService,
    ) {}

    /**
     * Computes and stores one assessment. `snapshot_json` freezes the reference
     * values and versions used, so editing config later never changes it (rule 8).
     */
    public function create(
        Beneficiary $beneficiary,
        ?Visit $visit = null,
        ?CarbonInterface $asOf = null,
        int $manualAdjustment = 0,
        string $status = 'draft',
    ): Assessment {
        $need = $this->needEngine->compute($beneficiary, $asOf, $manualAdjustment);
        $score = $this->scoreService->compute($beneficiary, $need, $asOf);

        $validDays = (int) Setting::value(
            'assessment_valid_days',
            config('sanabel.setting_defaults.assessment_valid_days')
        );

        return DB::transaction(function () use ($beneficiary, $visit, $need, $score, $status, $validDays) {
            if ($status === 'approved') {
                $beneficiary->assessments()
                    ->where('status', 'approved')
                    ->update(['status' => 'superseded']);
            }

            return Assessment::create([
                'beneficiary_id' => $beneficiary->getKey(),
                'visit_id' => $visit?->getKey(),
                'monthly_need' => $need['monthly_need'],
                'stable_income' => $need['stable_income'],
                'gap' => $need['gap'],
                'currency' => $need['currency'],
                'base_score' => $score['base_score'],
                'factors_json' => $score['factors'],
                'snapshot_json' => array_merge($need['snapshot'], $score['snapshot']),
                'valid_until' => now()->addDays($validDays)->toDateString(),
                'status' => $status,
            ]);
        });
    }

    public function approve(Assessment $assessment, int $approverId): Assessment
    {
        return DB::transaction(function () use ($assessment, $approverId) {
            $assessment->beneficiary->assessments()
                ->where('status', 'approved')
                ->whereKeyNot($assessment->getKey())
                ->update(['status' => 'superseded']);

            $assessment->update([
                'status' => 'approved',
                'approved_by' => $approverId,
                'approved_at' => now(),
            ]);

            $this->scheduleReassessment($assessment->beneficiary);

            return $assessment->refresh();
        });
    }

    /**
     * Admin may change a score. The automatic score is never erased —
     * it is copied onto the override row before the new one is stored.
     */
    public function override(
        Assessment $assessment,
        float $newScore,
        string $reasonAr,
        int $requestedBy,
        int $approvedBy,
        ?CarbonInterface $expiresAt = null,
    ): Override {
        return Override::create([
            'assessment_id' => $assessment->getKey(),
            'auto_score' => $assessment->base_score,
            'new_score' => $newScore,
            'reason_ar' => $reasonAr,
            'requested_by' => $requestedBy,
            'approved_by' => $approvedBy,
            'expires_at' => $expiresAt,
        ]);
    }

    /** stable 180 days · severe or sponsored 90 · emergency or job loss 30 (docs/03-rules.md §10). */
    public function scheduleReassessment(Beneficiary $beneficiary): void
    {
        $defaults = config('sanabel.setting_defaults');

        $severe = $beneficiary->healthRecords()->where('severity_band', '>=', 75)->exists();
        $sponsored = $beneficiary->sponsorships()->where('status', 'active')->exists();
        $emergency = $beneficiary->urgency_deadline_at !== null;

        $days = match (true) {
            $emergency => (int) Setting::value('reassessment_days_emergency', $defaults['reassessment_days_emergency']),
            $severe || $sponsored => (int) Setting::value('reassessment_days_severe', $defaults['reassessment_days_severe']),
            default => (int) Setting::value('reassessment_days_stable', $defaults['reassessment_days_stable']),
        };

        $beneficiary->forceFill([
            'last_assessment_at' => now(),
            'next_assessment_due_at' => now()->addDays($days),
        ])->save();
    }
}
