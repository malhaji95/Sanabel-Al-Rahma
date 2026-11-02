<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\Complaint;
use App\Models\Disbursement;
use App\Models\DisbursementFunding;
use App\Models\DisbursementOrder;
use App\Models\DonationAllocation;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The path money takes on its way out, as the association set it out on
 * 6 and 8 October:
 *
 *   case officer confirms  ->  treasurer gathers into one order  ->
 *   executive director approves (whole order, or line by line)   ->
 *   treasurer executes      ->  accountant reconciles
 *
 * Two rules sit under all of it. The order freezes on approval, so nothing
 * joins or leaves it afterwards. And nobody approves their own work: the
 * account that raised the order cannot be the account that approves it.
 */
class DisbursementService
{
    public function __construct(
        private readonly CoverageService $coverage,
        private readonly NotificationService $notifications,
        // T-226 — the transfer's own evidence, stored like every other
        // document the system keeps.
        private readonly MediaService $media,
    ) {}

    /**
     * A payment owed to a family, confirmed by the case officer.
     *
     * The ceiling is not a figure in a setting: it is the family's own monthly
     * need, less whatever already reached them this month. Anything beyond it
     * carries to the next month rather than being paid twice (decision of
     * 6 October).
     */
    public function confirm(Beneficiary $beneficiary, int $amount, User $officer, ?Carbon $period = null): Disbursement
    {
        $period = ($period ?? $this->coverage->currentMonth())->copy()->startOfMonth();

        /*
         | T-229 — before anything about the amount: whether this file may
         | be paid at all.
         |
         | Checked here rather than on the screen, because the screen is one
         | of several ways in — a bulk confirmation, a seeder, a command, a
         | later screen — and money leaving a refused file is not a thing to
         | be prevented by a form.
         */
        if (! $beneficiary->mayReceiveMoney()) {
            throw new \RuntimeException(__('sanabel.disbursement.file_may_not_receive', [
                'status' => __('sanabel.status.'.$beneficiary->status),
            ]));
        }

        if ($amount <= 0) {
            throw new \RuntimeException(__('sanabel.disbursement.amount_positive'));
        }

        $ceiling = $this->remainingForPeriod($beneficiary, $period);

        if ($amount > $ceiling) {
            throw new \RuntimeException(__('sanabel.disbursement.over_monthly_need', [
                'ceiling' => number_format($ceiling),
            ]));
        }

        /*
         | T-213 — «لازم نمنع صرف نفس التبرع مرتين», 1 November.
         |
         | The line is written and its money is drawn in one transaction.
         | Drawn here rather than at the transfer, because two orders can be
         | approved over the same donation long before either is paid, and
         | by then the second one is a decision somebody has already signed.
         |
         | Rule 6: the allocations are locked before they are read, so two
         | desks confirming at the same instant cannot both see the same
         | balance and both spend it.
         */
        return DB::transaction(function () use ($beneficiary, $amount, $period, $officer) {
            $line = Disbursement::create([
                'beneficiary_id' => $beneficiary->getKey(),
                'amount' => $amount,
                'currency' => config('sanabel.currency'),
                'period' => $period,
                'status' => 'confirmed',
                'confirmed_by' => $officer->getKey(),
                'confirmed_at' => now(),
            ]);

            $this->drawEarmarked($line);

            return $line;
        });
    }

    /**
     * T-213 — takes this payment's money out of the family's earmarked
     * donations, oldest first, and records what it took from each.
     *
     * Oldest first because a donation that has waited longest is the one the
     * association means to spend, and because any other order would need a
     * rule nobody has written.
     *
     * A payment may be larger than everything earmarked for the family — the
     * rest comes from the general fund, which is not held per donor and has
     * no row to draw from. That is not an error: it draws what exists and
     * leaves the remainder unbacked, which is the truth of where the money
     * came from.
     *
     * The database refuses a draw past what was given (the CHECK on
     * `drawn_amount`), so the arithmetic here is checked rather than trusted.
     */
    private function drawEarmarked(Disbursement $line): void
    {
        $remaining = (int) $line->amount;

        $allocations = DonationAllocation::query()
            ->where('donation_allocations.beneficiary_id', $line->beneficiary_id)
            ->join('donations', 'donations.id', '=', 'donation_allocations.donation_id')
            // Only money that actually arrived, and never a reversal row.
            ->where('donations.status', 'verified')
            ->whereNull('donations.deleted_at')
            ->whereNull('donations.reversal_of_id')
            ->whereColumn('donation_allocations.drawn_amount', '<', 'donation_allocations.amount')
            ->orderBy('donation_allocations.id')
            ->select('donation_allocations.*')
            ->lockForUpdate()
            ->get();

        foreach ($allocations as $allocation) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, $allocation->undrawn());

            if ($take <= 0) {
                continue;
            }

            $allocation->forceFill(['drawn_amount' => (int) $allocation->drawn_amount + $take])->save();

            DisbursementFunding::create([
                'disbursement_id' => $line->getKey(),
                'donation_allocation_id' => $allocation->getKey(),
                'amount' => $take,
                'currency' => $line->currency,
            ]);

            $remaining -= $take;
        }
    }

    /**
     * T-213 — and gives it back when the payment does not happen.
     *
     * A rejected or failed line must release what it drew, or one bounced
     * transfer would lock a donor's money out of the family it was given to
     * for good.
     */
    private function releaseEarmarked(Disbursement $line): void
    {
        DB::transaction(function () use ($line) {
            $fundings = DisbursementFunding::where('disbursement_id', $line->getKey())->get();

            foreach ($fundings as $funding) {
                $allocation = DonationAllocation::whereKey($funding->donation_allocation_id)
                    ->lockForUpdate()
                    ->first();

                $allocation?->forceFill([
                    'drawn_amount' => max(0, (int) $allocation->drawn_amount - (int) $funding->amount),
                ])->save();

                $funding->delete();
            }
        });
    }

    /**
     * What this family may still be paid for this month: their need, less what
     * has already been confirmed for the same month on another line, and less
     * what another body paid for the same month (approved 11 October). The
     * association does not pay a need somebody else has already answered.
     */
    public function remainingForPeriod(Beneficiary $beneficiary, Carbon $period): int
    {
        $need = $this->coverage->needAmount($beneficiary);

        $alreadyOwed = (int) Disbursement::where('beneficiary_id', $beneficiary->getKey())
            ->whereDate('period', $period->copy()->startOfMonth())
            ->whereNotIn('status', ['rejected', 'failed'])
            ->sum('amount');

        $external = $this->coverage->externalForMonth($beneficiary, $period);

        return max(0, $need - $alreadyOwed - $external);
    }

    /** The treasurer gathers confirmed payments into one order. */
    public function gather(iterable $disbursements, User $treasurer, ?string $notesAr = null): DisbursementOrder
    {
        return DB::transaction(function () use ($disbursements, $treasurer, $notesAr) {
            $order = DisbursementOrder::create([
                'reference_no' => $this->nextReference(),
                'status' => 'draft',
                'currency' => config('sanabel.currency'),
                'created_by' => $treasurer->getKey(),
                'notes_ar' => $notesAr,
            ]);

            foreach ($disbursements as $disbursement) {
                $this->addTo($order, $disbursement);
            }

            return $order->refresh();
        });
    }

    public function addTo(DisbursementOrder $order, Disbursement $disbursement): Disbursement
    {
        if ($order->isFrozen()) {
            throw new \RuntimeException(__('sanabel.disbursement.order_frozen'));
        }

        if ($disbursement->status !== 'confirmed') {
            throw new \RuntimeException(__('sanabel.disbursement.only_confirmed_join'));
        }

        $disbursement->forceFill([
            'disbursement_order_id' => $order->getKey(),
            'status' => 'in_order',
        ])->save();

        return $disbursement->refresh();
    }

    public function remove(Disbursement $disbursement): Disbursement
    {
        if ($disbursement->order?->isFrozen()) {
            throw new \RuntimeException(__('sanabel.disbursement.order_frozen'));
        }

        $disbursement->forceFill([
            'disbursement_order_id' => null,
            'status' => 'confirmed',
        ])->save();

        return $disbursement->refresh();
    }

    /** Hands the order to the executive director. */
    public function submit(DisbursementOrder $order): DisbursementOrder
    {
        if ($order->disbursements()->count() === 0) {
            throw new \RuntimeException(__('sanabel.disbursement.order_empty'));
        }

        if ($order->status !== 'draft') {
            throw new \RuntimeException(__('sanabel.disbursement.already_submitted'));
        }

        $order->forceFill(['status' => 'pending_approval', 'submitted_at' => now()])->save();

        /*
         | T-141 — the order waits on somebody, and that somebody is told.
         |
         | «بعد ما امر الصرف راح للمدير التنفيذي وتم اعتماده رجع عند امين
         | الصندوق ما عطاني اي حالة محددة», 28 October. Every step of this
         | path moved the row and told nobody, so the next pair of hands
         | learned about it by opening the screen and looking. The payload
         | carries the order's reference and nothing else (rule 10).
         */
        $this->tell('approve_disbursement', 'disbursement_order_submitted', $order);

        return $order->refresh();
    }

    /**
     * Approval of the whole order, duly recorded. Lines already rejected one
     * by one stay rejected; everything else is approved and the order freezes.
     */
    public function approve(DisbursementOrder $order, User $approver): DisbursementOrder
    {
        if ($order->status !== 'pending_approval') {
            throw new \RuntimeException(__('sanabel.disbursement.not_awaiting_approval'));
        }

        if ($order->created_by !== null && $order->created_by === $approver->getKey()) {
            throw new \RuntimeException(__('sanabel.disbursement.no_self_approval'));
        }

        return DB::transaction(function () use ($order, $approver) {
            $order->disbursements()->where('status', 'in_order')->get()
                ->each(fn (Disbursement $d) => $d->forceFill([
                    'status' => 'approved',
                    'approved_by' => $approver->getKey(),
                    'approved_at' => now(),
                ])->save());

            $order->forceFill([
                'status' => 'approved',
                'approved_by' => $approver->getKey(),
                'approved_at' => now(),
                'approved_duly' => true,
            ])->save();

            // Approved means «the treasurer may now move this money», and
            // until T-141 the treasurer was not told so.
            $this->tell('execute_disbursement', 'disbursement_order_approved', $order);

            return $order->refresh();
        });
    }

    /**
     * Tells the holders of one permission that an order has moved a step.
     *
     * The reference and nothing more: no family, no amount, no name (rule 10).
     * A reader who may open the order reads the rest inside it.
     */
    private function tell(string $permissionKey, string $template, ?DisbursementOrder $order): void
    {
        if (! $order) {
            return;
        }

        foreach (User::holdersOf($permissionKey) as $userId) {
            $this->notifications->send($userId, $template, ['ref' => $order->reference_no]);
        }
    }

    /** One line set aside while the rest of the order goes through. */
    public function reject(Disbursement $disbursement, User $approver, string $reasonAr): Disbursement
    {
        if (! in_array($disbursement->status, ['in_order', 'approved'], true)) {
            throw new \RuntimeException(__('sanabel.disbursement.cannot_reject'));
        }

        // T-213 — a line set aside gives its earmarked money back, so the
        // next order may spend it.
        $this->releaseEarmarked($disbursement);

        $disbursement->forceFill([
            'status' => 'rejected',
            'approved_by' => $approver->getKey(),
            'approved_at' => now(),
            'reject_reason_ar' => $reasonAr,
        ])->save();

        return $disbursement->refresh();
    }

    /**
     * The treasurer moves the money and names the transfer that carried it.
     *
     * T-226 — «ما منقبل تحويل بدون مرجع قابل للمطابقة وإثبات تنفيذ»,
     * 1 November.
     *
     * The reference was already required and the proof was not: this method
     * took an id that no screen ever supplied, so every transfer the system
     * has recorded is unevidenced. It takes the uploaded file now and stores
     * it against the line, and it refuses a transfer that carries neither a
     * new file nor a proof already on the record.
     *
     * `$proof` is the file the treasurer just uploaded; `$proofMediaId` is
     * kept for a caller that already holds a stored document.
     */
    public function execute(
        Disbursement $disbursement,
        User $treasurer,
        string $transferRef,
        ?int $proofMediaId = null,
        ?UploadedFile $proof = null,
    ): Disbursement {
        if ($disbursement->status !== 'approved') {
            throw new \RuntimeException(__('sanabel.disbursement.approve_before_executing'));
        }

        // T-105 — whoever authorised the payment does not also move the money.
        $this->refuseSameHand($disbursement, $treasurer, ['approved_by']);

        if ($proof) {
            $proofMediaId = $this->media
                ->store($proof, $disbursement, 'transfer_proof', $treasurer)
                ->getKey();
        }

        $proofMediaId ??= $disbursement->proof_media_id;

        if (! $proofMediaId) {
            throw new \RuntimeException(__('sanabel.disbursement.proof_required'));
        }

        $disbursement->forceFill([
            'status' => 'executed',
            'executed_by' => $treasurer->getKey(),
            'executed_at' => now(),
            'transfer_ref' => $transferRef,
            'proof_media_id' => $proofMediaId,
            // From here the family has their say, for as many days as the
            // association sets. Silence is not consent.
            'confirm_due_at' => now()->addDays(self::confirmDays()),
        ])->save();

        $this->settle($disbursement->order?->refresh());

        // And the accountant, who reconciles what left.
        $this->tell('reconcile_disbursement', 'disbursement_executed', $disbursement->order);

        /*
         | T-143 — «ويرجع لامين الصندوق ويعمل التحويل وتجي رسالة تأكيد
         | التحويل», 28 October: the account that raised this line is told
         | that the money it asked for has gone. It is the one person on the
         | path who otherwise never hears the end of their own request.
         */
        if ($disbursement->confirmed_by) {
            $this->notifications->send(
                $disbursement->confirmed_by,
                'disbursement_executed',
                ['ref' => $disbursement->order?->reference_no ?? $disbursement->transfer_ref],
            );
        }

        /*
         | T-215 — «رسالة المستفيد تنرسل بعد تسجيل تنفيذ التحويل وإثباته, مو
         | بمجرد اعتماد أمر الصرف», 1 November.
         |
         | It is sent from here and from nowhere else, which is what they
         | asked: `approve()` tells the treasurer and no one else, so no
         | household has ever been told about money that had not moved. What
         | was missing is that none was told when it did move either — they
         | learned it only by opening their own page.
         |
         | And it is sent after the reference and the proof are written
         | above, not before: a household asked to confirm a transfer whose
         | reference does not yet exist has nothing to confirm against.
         |
         | T-224 — and it carries the figure. The first draft of this
         | message did not, on the reasoning that T-141 keeps every amount
         | out of a notification body. That was right for the five messages
         | T-141 was written for, which go to staff about other people's
         | money; it is wrong for this one. The association said so in their
         | own recording of 28 October — «تم تحويل لك مثلا مبلغ, كل فقير
         | حسب المبلغ اللي له» — and they are right: a household cannot
         | confirm a sum nobody told them.
         */
        $this->notifications->send(
            $disbursement->beneficiary?->user_id,
            'disbursement_to_confirm',
            [
                'file_number' => $disbursement->beneficiary?->file_number,
                'amount' => number_format($disbursement->amount).' '.Money::short($disbursement->currency),
            ],
        );

        return $disbursement->refresh();
    }

    /** A line that was attempted and did not land, with its reason on the record. */
    public function fail(Disbursement $disbursement, string $reasonAr): Disbursement
    {
        if ($disbursement->status !== 'approved') {
            throw new \RuntimeException(__('sanabel.disbursement.approve_before_executing'));
        }

        // T-213 — a transfer that bounced gives its money back too, or one
        // bad account number would lock a donor's gift away for good.
        $this->releaseEarmarked($disbursement);

        $disbursement->forceFill(['status' => 'failed', 'failure_reason_ar' => $reasonAr])->save();

        $this->settle($disbursement->order?->refresh());

        return $disbursement->refresh();
    }

    /**
     * T-214 — the data desk finishes following a line up.
     *
     * «اللي أكد الاستلام وكان عنده إثبات تحويل صحيح، بيقفل متابعة الطلب»,
     * 1 November — and, in the same breath, «إغلاق متابعة المستفيد غير
     * إغلاق التدقيق المالي».
     *
     * So this closes nothing about the money. The line keeps its status,
     * keeps its place in the accountant's queue, and is reconciled later by
     * somebody else. What it records is that a desk asked the household,
     * got its answer, and is done.
     *
     * Only a line the household itself confirmed: closing the follow-up of
     * a payment nobody has answered for would be the desk answering on
     * their behalf, and the association's rule of 8 October — silence is
     * neither a yes nor a no — is the whole reason that is refused.
     */
    public function closeFollowUp(Disbursement $disbursement, User $officer): Disbursement
    {
        if ($disbursement->status !== 'received') {
            throw new \RuntimeException(__('sanabel.disbursement.only_received_closes'));
        }

        $disbursement->forceFill([
            'followup_closed_by' => $officer->getKey(),
            'followup_closed_at' => now(),
        ])->save();

        return $disbursement->refresh();
    }

    /**
     * T-214 — and hands a disputed one back to the treasurer.
     *
     * «واللي ما استلم أو عنده مشكلة، بيرجعه لأمين الصندوق للمعالجة، وبيظل
     * مفتوح لحد ما تنحل المشكلة».
     *
     * It stays `disputed`, which is what «يظل مفتوح» means here: nothing
     * about the line is closed or settled by handing it on. The treasurer
     * is told, and the line is stamped so the desk can tell the ones it has
     * passed on from the ones still in front of it.
     */
    public function returnToTreasurer(Disbursement $disbursement, User $officer): Disbursement
    {
        if ($disbursement->status !== 'disputed') {
            throw new \RuntimeException(__('sanabel.disbursement.only_disputed_returns'));
        }

        $disbursement->forceFill(['returned_to_treasurer_at' => now()])->save();

        // Told by file number rather than by order reference: the treasurer
        // is being sent to one household's payment, not to the batch it
        // happened to travel in, and a line raised outside an order has no
        // reference to quote.
        foreach (User::holdersOf('execute_disbursement') as $userId) {
            $this->notifications->send($userId, 'disbursement_disputed', [
                'file_number' => $disbursement->beneficiary?->file_number,
            ]);
        }

        return $disbursement->refresh();
    }

    /** The accountant ties the executed line to the bank. */
    public function reconcile(Disbursement $disbursement, User $accountant): Disbursement
    {
        if (! in_array($disbursement->status, ['executed', 'received'], true)) {
            throw new \RuntimeException(__('sanabel.disbursement.execute_before_reconciling'));
        }

        // T-105 — neither the hand that authorised the payment nor the hand
        // that moved it certifies that it landed.
        $this->refuseSameHand($disbursement, $accountant, ['approved_by', 'executed_by']);

        $disbursement->forceFill([
            'status' => 'reconciled',
            'reconciled_by' => $accountant->getKey(),
            'reconciled_at' => now(),
        ])->save();

        return $disbursement->refresh();
    }

    /**
     * T-105 — «نفس المستخدم لا يستطيع الجمع بين الخطوات المتعارضة للعملية
     * نفسها، حتى لو كان الدور يملك أكثر من صلاحية», 16 October.
     *
     * The four steps of a payment are four people, not four permissions. A
     * role may legitimately hold two of the keys — the treasurer both gathers
     * the order and moves the money — and that is exactly why the check is
     * made against the names already written on this line rather than against
     * what the actor is allowed to do.
     *
     * Raise against approve is checked on the order, where the raiser is
     * recorded; the two below are checked here, on the line.
     *
     * @param  array<int,string>  $columns  the earlier steps this one conflicts with
     */
    private function refuseSameHand(Disbursement $disbursement, User $actor, array $columns): void
    {
        foreach ($columns as $column) {
            if ($disbursement->{$column} !== null && (int) $disbursement->{$column} === $actor->getKey()) {
                throw new \RuntimeException(__('sanabel.disbursement.no_same_hand'));
            }
        }
    }

    /** How many days the household has to answer. Data, not a figure here. */
    public static function confirmDays(): int
    {
        return (int) Setting::value(
            'receipt_confirm_days',
            config('sanabel.setting_defaults.receipt_confirm_days')
        );
    }

    /** The household says the money arrived, and the payment closes. */
    public function confirmReceipt(Disbursement $disbursement, User $family): Disbursement
    {
        $this->guardTheirOwn($disbursement, $family);

        if (! $disbursement->awaitsBeneficiary()) {
            throw new \RuntimeException(__('sanabel.disbursement.not_awaiting_receipt'));
        }

        $disbursement->forceFill([
            'status' => 'received',
            'beneficiary_responded_at' => now(),
        ])->save();

        return $disbursement->refresh();
    }

    /**
     * The household says it did not arrive. That is an objection, not a note:
     * it opens a complaint with a reference number, tied to this payment, and
     * goes to the finance desk.
     *
     * It raises no replacement payment. What is owed instead, if anything, is
     * the association's decision after it looks, not something a button
     * creates (decision of 8 October).
     */
    public function disputeReceipt(Disbursement $disbursement, User $family, string $reasonAr): Disbursement
    {
        $this->guardTheirOwn($disbursement, $family);

        if (! $disbursement->awaitsBeneficiary()) {
            throw new \RuntimeException(__('sanabel.disbursement.not_awaiting_receipt'));
        }

        return DB::transaction(function () use ($disbursement, $family, $reasonAr) {
            $complaint = Complaint::create([
                'reference_no' => 'CMP-'.now()->format('y').'-'.str_pad((string) (Complaint::count() + 1), 5, '0', STR_PAD_LEFT),
                'submitted_by' => $family->getKey(),
                'subject_ar' => __('sanabel.disbursement.dispute_subject'),
                'body_ar' => $reasonAr,
                'category' => 'financial',
                'status' => 'open',
            ]);

            $disbursement->forceFill([
                'status' => 'disputed',
                'beneficiary_responded_at' => now(),
                'dispute_reason_ar' => $reasonAr,
                'complaint_id' => $complaint->getKey(),
            ])->save();

            $this->tellFinance($disbursement, $complaint->reference_no);

            return $disbursement->refresh();
        });
    }

    /**
     * Silence is neither a yes nor a no.
     *
     * The association was explicit on 8 October: running the window out is
     * not a confirmation and does not close the payment. So nothing about
     * the payment's state moves — it is still waiting on the household, and
     * both of their answers are still open to them. All that happens is that
     * it is raised for follow-up, once, and the finance desk is told.
     */
    public function chaseUnconfirmed(): int
    {
        $overdue = Disbursement::query()
            ->where('status', 'executed')
            ->whereNull('escalated_at')
            ->whereNotNull('confirm_due_at')
            ->where('confirm_due_at', '<', now())
            ->get();

        foreach ($overdue as $disbursement) {
            $disbursement->forceFill(['escalated_at' => now()])->save();

            $this->tellFinance($disbursement, null);
        }

        return $overdue->count();
    }

    /** A payment belongs to one household, and only they answer for it. */
    private function guardTheirOwn(Disbursement $disbursement, User $family): void
    {
        if ($disbursement->beneficiary?->user_id !== $family->getKey()) {
            throw new \RuntimeException(__('sanabel.disbursement.not_your_payment'));
        }
    }

    private function tellFinance(Disbursement $disbursement, ?string $reference): void
    {
        foreach (User::verifiers() as $verifierId) {
            $this->notifications->send($verifierId, 'disbursement_disputed', array_filter([
                'file_number' => $disbursement->beneficiary?->file_number,
                'reference_no' => $reference,
            ]));
        }
    }

    /**
     * T-227 — withdrawing an order that has not been paid.
     *
     * «يُلغى أمر الصرف غير المنفّذ… يُسجّل سبب الإلغاء واسم المسؤول وتاريخه.
     *  يُنشأ أمر صرف جديد بالبيانات المصححة، ويمر بكامل مراحل المراجعة
     *  والاعتماد من البداية», 1 November.
     *
     * Not an edit. The association was explicit that an approved amount is
     * never quietly corrected: the order is withdrawn whole, with a reason
     * on the record, and a fresh one is raised and re-approved from the
     * start. This writes the withdrawal; raising the replacement is the
     * ordinary path, unchanged.
     *
     * Refused the moment any line has moved. «غير المنفّذ» is the whole
     * condition: once a pound has left, the correction is a reversal, which
     * is a different act with different evidence.
     *
     * The lines go back to `confirmed`, which is where they were before the
     * treasurer gathered them, so they may join the corrected order. What
     * they drew from the family's earmarked donations (T-213) stays drawn:
     * the family is still owed the money, and only the paperwork changed.
     */
    public function cancelOrder(DisbursementOrder $order, User $actor, string $reasonAr): DisbursementOrder
    {
        if (blank(trim($reasonAr))) {
            throw new \RuntimeException(__('sanabel.disbursement.cancel_reason_required'));
        }

        if (in_array($order->status, ['settled', 'cancelled'], true)) {
            throw new \RuntimeException(__('sanabel.disbursement.order_already_closed'));
        }

        $paid = $order->disbursements()
            ->whereIn('status', ['executed', 'received', 'disputed', 'reconciled'])
            ->exists();

        if ($paid) {
            throw new \RuntimeException(__('sanabel.disbursement.cannot_cancel_paid'));
        }

        return DB::transaction(function () use ($order, $actor, $reasonAr) {
            $order->disbursements()
                ->whereIn('status', ['in_order', 'approved'])
                ->get()
                ->each(fn (Disbursement $line) => $line->forceFill([
                    'status' => 'confirmed',
                    'disbursement_order_id' => null,
                    'approved_by' => null,
                    'approved_at' => null,
                ])->save());

            $order->forceFill([
                'status' => 'cancelled',
                'cancelled_by' => $actor->getKey(),
                'cancelled_at' => now(),
                'cancel_reason_ar' => $reasonAr,
            ])->save();

            return $order->refresh();
        });
    }

    /** Executing while any line is still waiting; settled once none is. */
    public function settle(?DisbursementOrder $order): ?DisbursementOrder
    {
        if (! $order) {
            return null;
        }

        $waiting = $order->disbursements()->whereIn('status', ['approved'])->exists();

        $order->forceFill([
            'status' => $waiting ? 'executing' : 'settled',
            'settled_at' => $waiting ? null : now(),
        ])->save();

        return $order->refresh();
    }

    private function nextReference(): string
    {
        $year = now()->format('y');
        $count = DisbursementOrder::withTrashed()->whereYear('created_at', now()->year)->count() + 1;

        return sprintf('DO-%s-%05d', $year, $count);
    }
}
