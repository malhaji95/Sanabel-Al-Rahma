<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\Complaint;
use App\Models\Disbursement;
use App\Models\DisbursementOrder;
use App\Models\Setting;
use App\Models\User;
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

        if ($amount <= 0) {
            throw new \RuntimeException(__('sanabel.disbursement.amount_positive'));
        }

        $ceiling = $this->remainingForPeriod($beneficiary, $period);

        if ($amount > $ceiling) {
            throw new \RuntimeException(__('sanabel.disbursement.over_monthly_need', [
                'ceiling' => number_format($ceiling),
            ]));
        }

        return Disbursement::create([
            'beneficiary_id' => $beneficiary->getKey(),
            'amount' => $amount,
            'currency' => config('sanabel.currency'),
            'period' => $period,
            'status' => 'confirmed',
            'confirmed_by' => $officer->getKey(),
            'confirmed_at' => now(),
        ]);
    }

    /**
     * What this family may still be paid for this month: their need, less what
     * has already been confirmed for the same month on another line.
     */
    public function remainingForPeriod(Beneficiary $beneficiary, Carbon $period): int
    {
        $need = $this->coverage->needAmount($beneficiary);

        $alreadyOwed = (int) Disbursement::where('beneficiary_id', $beneficiary->getKey())
            ->whereDate('period', $period->copy()->startOfMonth())
            ->whereNotIn('status', ['rejected', 'failed'])
            ->sum('amount');

        return max(0, $need - $alreadyOwed);
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

            return $order->refresh();
        });
    }

    /** One line set aside while the rest of the order goes through. */
    public function reject(Disbursement $disbursement, User $approver, string $reasonAr): Disbursement
    {
        if (! in_array($disbursement->status, ['in_order', 'approved'], true)) {
            throw new \RuntimeException(__('sanabel.disbursement.cannot_reject'));
        }

        $disbursement->forceFill([
            'status' => 'rejected',
            'approved_by' => $approver->getKey(),
            'approved_at' => now(),
            'reject_reason_ar' => $reasonAr,
        ])->save();

        return $disbursement->refresh();
    }

    /** The treasurer moves the money and names the transfer that carried it. */
    public function execute(Disbursement $disbursement, User $treasurer, string $transferRef, ?int $proofMediaId = null): Disbursement
    {
        if ($disbursement->status !== 'approved') {
            throw new \RuntimeException(__('sanabel.disbursement.approve_before_executing'));
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

        return $disbursement->refresh();
    }

    /** A line that was attempted and did not land, with its reason on the record. */
    public function fail(Disbursement $disbursement, string $reasonAr): Disbursement
    {
        if ($disbursement->status !== 'approved') {
            throw new \RuntimeException(__('sanabel.disbursement.approve_before_executing'));
        }

        $disbursement->forceFill(['status' => 'failed', 'failure_reason_ar' => $reasonAr])->save();

        $this->settle($disbursement->order?->refresh());

        return $disbursement->refresh();
    }

    /** The accountant ties the executed line to the bank. */
    public function reconcile(Disbursement $disbursement, User $accountant): Disbursement
    {
        if (! in_array($disbursement->status, ['executed', 'received'], true)) {
            throw new \RuntimeException(__('sanabel.disbursement.execute_before_reconciling'));
        }

        $disbursement->forceFill([
            'status' => 'reconciled',
            'reconciled_by' => $accountant->getKey(),
            'reconciled_at' => now(),
        ])->save();

        return $disbursement->refresh();
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
     * it opens a complaint with a reference number and goes back to finance.
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
     * Silence is not confirmation. A payment nobody answered for inside the
     * window goes back to the finance desk the same way an objection does.
     */
    public function chaseUnconfirmed(): int
    {
        $overdue = Disbursement::query()
            ->where('status', 'executed')
            ->whereNotNull('confirm_due_at')
            ->where('confirm_due_at', '<', now())
            ->get();

        foreach ($overdue as $disbursement) {
            $disbursement->forceFill([
                'status' => 'disputed',
                'dispute_reason_ar' => __('sanabel.disbursement.no_answer_in_window', [
                    'days' => self::confirmDays(),
                ]),
            ])->save();

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
