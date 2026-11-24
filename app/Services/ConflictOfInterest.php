<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\ConflictDisclosure;
use App\Models\ConflictWaiver;
use App\Models\Region;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * T-305 — «إضافة إفصاح إلزامي للمندوب والباحث عن أي صلة قرابة أو مصلحة مع
 * الأسرة», 12 November, which the association marked as the gravest of the
 * six things it asked for.
 *
 * The file already refused two things, and they are worth naming because
 * this is not them: a supervisor may not endorse a visit they made, and an
 * approver may not approve a file they created. Both guard a person against
 * their own hand. Neither says anything about the hand of a cousin.
 *
 * What this adds is a question, asked of each person before the file moves
 * by their doing. Three parts, in their words:
 *
 *   the disclosure is compulsory — nobody verifies or approves a file they
 *   have not answered for;
 *
 *   a stated tie takes that person off the deciding of that file, and off
 *   nothing else: they may still record what they saw, because collecting is
 *   not deciding and the association asked for exactly that separation;
 *
 *   and the bar lifts only for a named person, by somebody else holding a key
 *   of their own, with a reason written down — all of it in the audit log,
 *   where the oversight committee reads it.
 *
 * Nothing here guesses at a relationship. The system has no way to know that
 * a researcher is somebody's cousin and no business pretending it does; what
 * it can do is make the question unavoidable and the answer binding.
 */
class ConflictOfInterest
{
    /** The acts a disclosure stands between a person and a file. */
    public const GUARDED_ACTS = ['verify', 'endorse', 'approve'];

    /** The permission that lifts a bar. Held by nobody who merely approves. */
    public const WAIVER_KEY = 'waive_conflict';

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly NotificationService $notifications,
    ) {}

    /** What this person has said about this file, if they have said anything. */
    public function disclosureFor(Beneficiary $case, User $user): ?ConflictDisclosure
    {
        return ConflictDisclosure::query()
            ->where('beneficiary_id', $case->getKey())
            ->where('user_id', $user->getKey())
            ->first();
    }

    /** True while the question is unanswered. */
    public function mustDeclare(Beneficiary $case, User $user): bool
    {
        return $this->disclosureFor($case, $user) === null;
    }

    /**
     * The answer, recorded. Saying «نعم» is doing the right thing, so nothing
     * here treats it as an accusation: it is written down, it bars this
     * person from deciding this file, and that is all.
     */
    public function declare(
        Beneficiary $case,
        User $user,
        bool $hasRelationship,
        ?string $relationType = null,
        ?string $note = null,
    ): ConflictDisclosure {
        if ($hasRelationship && ! in_array($relationType, ConflictDisclosure::RELATIONS, true)) {
            throw new RuntimeException(__('sanabel.conflict.relation_required'));
        }

        $disclosure = ConflictDisclosure::updateOrCreate(
            ['beneficiary_id' => $case->getKey(), 'user_id' => $user->getKey()],
            [
                'has_relationship' => $hasRelationship,
                'relation_type' => $hasRelationship ? $relationType : null,
                'relation_note_ar' => $hasRelationship ? $note : null,
                'declared_at' => now(),
            ],
        );

        if ($hasRelationship) {
            $this->callAnotherHand($case, $user);
        }

        return $disclosure;
    }

    /*
     | T-325 — «وين بيروح الطلب بعدها؟», the association, 24 November.
     |
     | The bar works as they asked: the family is registered and the file is
     | saved; it is the declarer who may not sign it off. And the way out
     | exists — the area's supervisor, their deputy, and any other delegate in
     | scope all hold the field sign-off, so one of them takes it.
     |
     | What was missing is that none of them knew. The file sat in «بانتظار
     | زيارة المندوب» under the name of the very person who may not touch it,
     | looking like every other file waiting for a visit. A way out nobody is
     | told about is, to the person waiting, no way out at all.
     |
     | Rule 10 — the notice carries the file number. Not the family's name,
     | not the kind of tie, not a word of what was written: that a file needs
     | another hand is what the supervisor must act on, and why is the
     | oversight committee's to read on the file itself.
     */
    private function callAnotherHand(Beneficiary $case, User $declarer): void
    {
        $area = $this->areaOf($case);

        if (! $area) {
            return;
        }

        $places = Region::where('parent_id', $area)->pluck('id')->push($area);

        $supervisors = User::query()
            ->where('is_active', true)
            ->whereKeyNot($declarer->getKey())
            ->whereIn('region_id', $places)
            ->whereHas('role', fn ($q) => $q->whereIn('key', ['area_supervisor', 'deputy_area_supervisor']))
            ->pluck('id');

        foreach ($supervisors as $supervisorId) {
            $this->notifications->send($supervisorId, 'case_needs_another_hand', [
                'file_number' => $case->file_number,
            ]);
        }
    }

    /** The area a file sits in: itself if it is one, else the nearest above. */
    private function areaOf(Beneficiary $case): ?int
    {
        $region = $case->region;

        while ($region) {
            if ($region->type === 'area') {
                return $region->getKey();
            }

            $region = $region->parent;
        }

        return null;
    }

    /** A bar standing over this person on this file right now. */
    public function isBarred(Beneficiary $case, User $user): bool
    {
        return $this->disclosureFor($case, $user)?->has_relationship === true
            && ! $this->isWaived($case, $user);
    }

    /*
     | T-318 — a waiver that has run out does not lift anything. The row is
     | not deleted: it says the bar was lifted, by whom, why, and until when,
     | which is what the oversight committee reads it for. It simply stops
     | being the answer to «may this hand act today».
     */
    public function isWaived(Beneficiary $case, User $user): bool
    {
        return ConflictWaiver::query()
            ->where('beneficiary_id', $case->getKey())
            ->where('user_id', $user->getKey())
            ->where(fn ($q) => $q->whereNull('expires_on')
                ->orWhereDate('expires_on', '>=', now()->startOfDay()))
            ->exists();
    }

    /**
     * The one call every guarded act makes. It refuses twice over: once for
     * a question nobody answered, and once for an answer that bars them.
     */
    public function guard(Beneficiary $case, User $user, string $act): void
    {
        if (! in_array($act, self::GUARDED_ACTS, true)) {
            throw new RuntimeException('Unknown act: '.$act);
        }

        if ($this->mustDeclare($case, $user)) {
            throw new RuntimeException(__('sanabel.conflict.must_declare'));
        }

        if ($this->isBarred($case, $user)) {
            throw new RuntimeException(__('sanabel.conflict.barred', [
                'act' => __('sanabel.conflict.acts.'.$act),
            ]));
        }
    }

    /**
     * Lifting the bar.
     *
     * Three conditions, each of them refused separately so the message says
     * which one failed: the person lifting it holds the key, they are not
     * the person it is lifted for, and they wrote down why.
     */
    public function waive(
        Beneficiary $case,
        User $forUser,
        User $grantedBy,
        string $reason,
        ?Carbon $expiresOn = null,
    ): ConflictWaiver {
        if (! $this->permissions->has($grantedBy, self::WAIVER_KEY)) {
            throw new RuntimeException(__('sanabel.conflict.waiver_not_permitted'));
        }

        if ($grantedBy->getKey() === $forUser->getKey()) {
            throw new RuntimeException(__('sanabel.conflict.waiver_not_independent'));
        }

        if (trim($reason) === '') {
            throw new RuntimeException(__('sanabel.conflict.waiver_reason_required'));
        }

        /*
         | T-318 — a term that has already run out is not a term. Granting one
         | would read on the screen as a lifted bar and act as a standing one,
         | which is the worst of both.
         */
        if ($expiresOn && $expiresOn->lt(now()->startOfDay())) {
            throw new RuntimeException(__('sanabel.conflict.waiver_expiry_past'));
        }

        return DB::transaction(fn () => ConflictWaiver::updateOrCreate(
            ['beneficiary_id' => $case->getKey(), 'user_id' => $forUser->getKey()],
            [
                'granted_by' => $grantedBy->getKey(),
                'reason_ar' => $reason,
                'granted_at' => now(),
                'expires_on' => $expiresOn?->toDateString(),
            ],
        ));
    }
}
