<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\ChangeRequest;
use App\Models\Delivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * docs/03-rules.md §9, §10, §11 and the status flow in T-11.
 * A `status` column is the whole state machine — no package, no abstraction.
 */
class CaseService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly AssessmentService $assessments,
    ) {}

    /**
     * Verification passes through the delegate and then the area supervisor
     * before it reaches the admin, as the master phases document requires.
     * A file may be submitted from a draft or after a reassessment was asked for.
     */
    public const AWAITING_FIELD = ['draft', 'pending_visit', 'needs_reassessment'];

    /** The delegate's sign-off: the family was visited and the file matches. */
    public function verifyInField(Beneficiary $case, User $delegate): Beneficiary
    {
        if (! in_array($case->status, self::AWAITING_FIELD, true)) {
            throw new \RuntimeException(__('sanabel.cases.not_awaiting_field'));
        }

        $case->forceFill([
            'status' => 'verified',
            'field_verified_by' => $delegate->getKey(),
            'field_verified_at' => now(),
        ])->save();

        return $case->refresh();
    }

    /**
     * The area supervisor's sign-off, which puts the file in front of the
     * admin. Whoever verified it in the field cannot also endorse it: two
     * sign-offs by one person are one sign-off.
     */
    public function endorse(Beneficiary $case, User $supervisor): Beneficiary
    {
        if ($case->status !== 'verified') {
            throw new \RuntimeException(__('sanabel.cases.not_awaiting_endorsement'));
        }

        if ($case->field_verified_by !== null && $case->field_verified_by === $supervisor->getKey()) {
            throw new \RuntimeException(__('sanabel.cases.self_endorsement_blocked'));
        }

        $case->forceFill([
            'status' => 'pending_approval',
            'endorsed_by' => $supervisor->getKey(),
            'endorsed_at' => now(),
        ])->save();

        return $case->refresh();
    }

    /**
     * Separation of duties — the creator can never be the final approver, and
     * the admin only sees a file the two field steps have already passed.
     */
    public function approve(Beneficiary $case, User $approver): Beneficiary
    {
        if ($case->status !== 'pending_approval') {
            throw new \RuntimeException(__('sanabel.cases.not_awaiting_approval'));
        }

        if ($case->created_by !== null && $case->created_by === $approver->getKey()) {
            throw new \RuntimeException(__('sanabel.cases.self_approval_blocked'));
        }

        return DB::transaction(function () use ($case, $approver) {
            $case->forceFill([
                'status' => 'approved',
                'approved_by' => $approver->getKey(),
                'approved_at' => now(),
                'reject_reason_ar' => null,
            ])->save();

            $this->notifications->send($case->created_by, 'case_approved', [
                'file_number' => $case->file_number,
            ]);

            return $case->refresh();
        });
    }

    public function reject(Beneficiary $case, User $approver, string $reasonAr): Beneficiary
    {
        if (blank($reasonAr)) {
            throw new \RuntimeException(__('sanabel.cases.reject_reason_required'));
        }

        if ($case->created_by !== null && $case->created_by === $approver->getKey()) {
            throw new \RuntimeException(__('sanabel.cases.self_approval_blocked'));
        }

        $case->forceFill([
            'status' => 'rejected',
            'approved_by' => $approver->getKey(),
            'approved_at' => now(),
            'reject_reason_ar' => $reasonAr,
        ])->save();

        $this->notifications->send($case->created_by, 'case_rejected', [
            'file_number' => $case->file_number,
        ]);

        // A revoked approval hides the job profile with it.
        $case->jobProfile?->update(['status' => 'hidden']);

        return $case->refresh();
    }

    public function publish(Beneficiary $case): Beneficiary
    {
        if ($case->status !== 'approved') {
            throw new \RuntimeException('Only an approved case can be published.');
        }

        $case->forceFill(['status' => 'published', 'published_at' => now()])->save();

        return $case->refresh();
    }

    public function suspend(Beneficiary $case, string $reasonAr): Beneficiary
    {
        $case->forceFill(['status' => 'suspended', 'reject_reason_ar' => $reasonAr])->save();
        $case->jobProfile?->update(['status' => 'hidden']);

        return $case->refresh();
    }

    public function graduate(Beneficiary $case): Beneficiary
    {
        $case->forceFill(['status' => 'graduated'])->save();

        return $case->refresh();
    }

    /**
     * Rule 9 — funding is not completion. A case closes only when a delivery
     * row exists with proof.
     */
    public function close(Beneficiary $case): Beneficiary
    {
        $hasProof = $case->deliveries()
            ->whereNotNull('proof_media_id')
            ->whereNotNull('confirmed_at')
            ->exists();

        if (! $hasProof) {
            throw new \RuntimeException(__('sanabel.cases.close_requires_proof'));
        }

        $case->forceFill(['status' => 'graduated'])->save();

        return $case->refresh();
    }

    public function confirmDelivery(Beneficiary $case, User $confirmer, string $type, int $proofMediaId, ?int $donationId = null): Delivery
    {
        return Delivery::create([
            'beneficiary_id' => $case->getKey(),
            'donation_id' => $donationId,
            'type' => $type,
            'proof_media_id' => $proofMediaId,
            'confirmed_by' => $confirmer->getKey(),
            'confirmed_at' => now(),
        ]);
    }

    /**
     * Rule 7 (backlog T-13) — after approval, edits go through review.
     * A material field triggers a recompute when the change is applied.
     *
     * The request targets the record that actually holds the field. Fifteen of
     * the seventeen material fields live on the housing row, an income, a
     * member or a health record rather than on the case itself; pointing every
     * request at the beneficiary meant the before/after was empty and the
     * approved change was filled into a model that does not have the column,
     * so it was silently never applied.
     */
    public function requestChange(
        Beneficiary $case,
        User $requester,
        array $payload,
        string $reasonAr,
        ?Model $target = null,
    ): ChangeRequest {
        $target ??= $this->resolveChangeTarget($case, $payload);

        return ChangeRequest::create([
            'entity_type' => $target::class,
            'entity_id' => $target->getKey(),
            'beneficiary_id' => $case->getKey(),
            'payload_json' => $payload,
            'old_json' => array_intersect_key($target->getAttributes(), $payload),
            'reason_ar' => $reasonAr,
            'is_material' => ChangeRequest::isMaterial($payload),
            'requested_by' => $requester->getKey(),
            'status' => 'pending',
        ]);
    }

    /**
     * Which record a payload belongs to. The case and its housing row are the
     * two a payload can name on its own; a family may have several incomes,
     * members or health records, so one of those has to be passed explicitly
     * rather than guessed at.
     */
    private function resolveChangeTarget(Beneficiary $case, array $payload): Model
    {
        $fields = array_keys($payload);

        if ($fields === array_values(array_intersect($fields, array_keys($case->getAttributes())))) {
            return $case;
        }

        $housing = $case->housing()->first();

        if ($housing && $fields === array_values(array_intersect($fields, array_keys($housing->getAttributes())))) {
            return $housing;
        }

        throw new \InvalidArgumentException(
            'A change to '.implode(', ', $fields).' must name the record it belongs to.'
        );
    }

    public function approveChange(ChangeRequest $request, User $reviewer): ChangeRequest
    {
        return DB::transaction(function () use ($request, $reviewer) {
            $model = $request->entity_type::findOrFail($request->entity_id);
            $model->fill($request->payload_json)->save();

            $request->forceFill([
                'status' => 'approved',
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
            ])->save();

            if ($request->is_material) {
                $case = $model instanceof Beneficiary ? $model : $model->beneficiary;
                $this->assessments->create($case, status: 'approved');
            }

            return $request->refresh();
        });
    }

    public function rejectChange(ChangeRequest $request, User $reviewer, string $noteAr): ChangeRequest
    {
        $request->forceFill([
            'status' => 'rejected',
            'reviewed_by' => $reviewer->getKey(),
            'reviewed_at' => now(),
            'review_note_ar' => $noteAr,
        ])->save();

        return $request->refresh();
    }

    /**
     * Rule 10 — past due becomes a flag and a demotion in ranking.
     * Existing support is never stopped.
     */
    public function flagOverdueReassessments(): int
    {
        $due = Beneficiary::query()
            ->whereIn('status', ['published', 'approved'])
            ->whereNotNull('next_assessment_due_at')
            ->where('next_assessment_due_at', '<=', now())
            ->get();

        foreach ($due as $case) {
            $case->forceFill(['status' => 'needs_reassessment'])->save();

            $this->notifications->send($case->created_by, 'reassessment_due', [
                'file_number' => $case->file_number,
            ]);
        }

        return $due->count();
    }
}
