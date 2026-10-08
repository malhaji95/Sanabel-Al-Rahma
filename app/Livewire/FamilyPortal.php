<?php

namespace App\Livewire;

use App\Models\Beneficiary;
use App\Models\Disbursement;
use App\Services\CoverageService;
use App\Services\DisbursementService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The household's own page.
 *
 * It shows them their file and the payments made to it, and it is where they
 * answer the one question nobody else may answer for them: did the money
 * arrive. The person who moved it never confirms its receipt (decision of
 * 6 October).
 *
 * Everything here belongs to the family looking at it. There is no file
 * number in a parameter and nothing to change in an address bar: the file is
 * found from the account.
 */
class FamilyPortal extends Component
{
    public ?string $notice = null;

    public ?string $error = null;

    /** Which payment the objection box is open for. */
    public ?int $disputing = null;

    public string $reason = '';

    public function family(): ?Beneficiary
    {
        return Beneficiary::where('user_id', auth()->id())->first();
    }

    public function confirm(int $id): void
    {
        $this->run($id, fn (Disbursement $d) => app(DisbursementService::class)
            ->confirmReceipt($d, auth()->user()), __('sanabel.disbursement.thanks_confirmed'));
    }

    public function dispute(int $id): void
    {
        $this->validate(
            ['reason' => ['required', 'string', 'min:5', 'max:1000']],
            attributes: ['reason' => __('sanabel.disbursement.dispute_receipt')],
        );

        $this->run($id, fn (Disbursement $d) => app(DisbursementService::class)
            ->disputeReceipt($d, auth()->user(), $this->reason), __('sanabel.disbursement.dispute_recorded'));

        $this->reason = '';
        $this->disputing = null;
    }

    private function run(int $id, callable $step, string $success): void
    {
        $this->notice = $this->error = null;

        // Found through the family, so an id typed into the page reaches
        // nothing that is not theirs.
        $disbursement = $this->family()?->disbursements()->whereKey($id)->first();

        if (! $disbursement) {
            $this->error = __('sanabel.disbursement.not_your_payment');

            return;
        }

        try {
            $step($disbursement);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->notice = $success;
    }

    public function render(): View
    {
        $family = $this->family();

        return view('livewire.family-portal', [
            'family' => $family,
            'coverage' => $family ? app(CoverageService::class)->coveragePercent($family) : 0,
            'need' => $family ? app(CoverageService::class)->needAmount($family) : 0,
            'payments' => $family
                ? $family->disbursements()
                    ->whereIn('status', ['executed', 'received', 'disputed', 'failed', 'reconciled'])
                    ->latest('executed_at')
                    ->get()
                : collect(),
            'confirmDays' => DisbursementService::confirmDays(),
        ]);
    }
}
