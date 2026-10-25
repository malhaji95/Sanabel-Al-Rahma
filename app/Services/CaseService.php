<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\ChangeRequest;
use App\Models\Delivery;
use App\Models\User;
use App\Policies\BeneficiaryPolicy;
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

        /*
         | 16 Oct 2026 — a partner association enters the file and signs
         | nothing on it. The step says somebody went to the house, so it
         | belongs to the people who do the visiting. Checked here as well as
         | in the policy: the screen asks the policy, and this is what answers
         | a call that did not come from the screen.
         */
        if (! in_array($delegate->role?->key, BeneficiaryPolicy::FIELD_VERIFIERS, true)) {
            throw new \RuntimeException(__('sanabel.cases.field_verifier_only'));
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

    /**
     * T-125 — the file goes back to the field, with the note that sent it.
     *
     * «المشرف دخل وعطاه ملاحظات بعد المندوب, كيف بترجع الحالة للمندوب؟»,
     * 26 October. There was no answer: a supervisor could endorse a file or
     * leave it sitting, and rejection is the director's word and means the
     * family was refused — not that a page of the form is missing.
     *
     * So this is the third thing that can happen to a file in review. It
     * goes back to «بانتظار الزيارة», which is where the delegate's own
     * queue reads from, and the note is written where the next person to
     * open the file will read it. Nothing about the family is decided by it.
     */
    public function returnToField(Beneficiary $case, User $reviewer, string $noteAr): Beneficiary
    {
        if (blank($noteAr)) {
            throw new \RuntimeException(__('sanabel.cases.return_note_required'));
        }

        if (! in_array($case->status, ['verified', 'pending_approval'], true)) {
            throw new \RuntimeException(__('sanabel.cases.not_returnable'));
        }

        return DB::transaction(function () use ($case, $noteAr) {
            $case->forceFill([
                'status' => 'pending_visit',
                'reject_reason_ar' => $noteAr,
                // The sign-offs below this step are given back: whoever
                // picks the file up next signs it themselves.
                'field_verified_by' => null,
                'field_verified_at' => null,
                'endorsed_by' => null,
                'endorsed_at' => null,
            ])->save();

            $this->notifications->send($case->created_by, 'case_returned', [
                'file_number' => $case->file_number,
            ]);

            return $case->refresh();
        });
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
        /*
         | T-61 (AUTH-03) — refused here, where the request is raised, rather
         | than at approval. The payload was any array at all and an approved
         | one is filled straight into the model, so a request whose stated
         | reason was "a phone number" could carry status and published_at and
         | take a draft file past every step of the approval chain.
         */
        $forbidden = ChangeRequest::fieldsNotChangeable($payload);

        if ($forbidden !== []) {
            throw new \RuntimeException(__('sanabel.cases.field_not_changeable', [
                'fields' => implode('، ', $forbidden),
            ]));
        }

        if ($payload === []) {
            throw new \RuntimeException(__('sanabel.cases.change_needs_a_field'));
        }

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

    /**
     * T-59 (PAY-05) — two people, never one.
     *
     * Nobody approves their own request. It mattered least on a band and most
     * on the wallet: one account could otherwise raise a change to where a
     * family's money is sent and wave it through itself.
     */
    public function approveChange(ChangeRequest $request, User $reviewer): ChangeRequest
    {
        if ($request->requested_by && (int) $request->requested_by === (int) $reviewer->getKey()) {
            throw new \RuntimeException(__('sanabel.cases.change_needs_another_person'));
        }

        return DB::transaction(function () use ($request, $reviewer) {
            $model = $request->entity_type::findOrFail($request->entity_id);

            $payload = $request->payload_json;

            /*
             | The hash is what rule 11 matches a duplicate on, and it is not a
             | field a request may name. Changing the number without it left
             | duplicate detection silently pointing at the old one.
             */
            if ($model instanceof Beneficiary && array_key_exists('national_id_encrypted', $payload)) {
                $payload['national_id_hash'] = Beneficiary::hashNationalId(
                    (string) $payload['national_id_encrypted']
                );
            }

            // The one path allowed to write a live file's payment details.
            // fill(), not forceFill(): the allowlist above is the gate, and
            // each model's fillable list stays as the second one.
            Beneficiary::applyingApprovedChange(fn () => $model->fill($payload)->save());

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
