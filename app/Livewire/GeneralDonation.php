<?php

namespace App\Livewire;

use App\Payments\DuplicateTransactionRef;
use App\Payments\PaymentGateway;
use App\Services\TransferRouting;
use Livewire\Component;

/**
 * T-103 — giving without choosing a family.
 *
 * Asked for on 15 October: «لما يضغط على دفع مباشرة ياخذ مباشرة على دفع
 * مباشرة، ما يحتاج يحط رقم ملف أو ما يحتاج يدخل أي شيء». Until now the only
 * way to give was to pick a file, put it in a basket, reserve it and then
 * record the transfer — four screens between a donor and the thing they came
 * to do, and the first of them asks a question many donors have no answer to.
 *
 * Nothing new happens to the money. The domain has had two kinds of donation
 * since the beginning and admits no third: money earmarked for the files in a
 * basket, and general money the association spends at its discretion. This
 * screen is the second kind, which had no door. It reaches the same wallet as
 * every other donation, because a donor never pays a household directly.
 *
 * The amount and the transfer reference are still asked for, and always will
 * be: a human moves the money outside the platform and the system records
 * what they say they moved. What is not asked for is a file number.
 */
class GeneralDonation extends Component
{
    public ?int $amount = null;

    public string $transactionRef = '';

    public ?string $notice = null;

    public ?string $error = null;

    /** Shown once, under the confirmation, in the association's own words. */
    public bool $thanks = false;

    public function record(): void
    {
        $this->error = $this->notice = null;
        $this->thanks = false;

        $this->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'transactionRef' => ['required', 'string', 'max:191'],
        ], attributes: [
            'amount' => __('sanabel.donation.amount'),
            'transactionRef' => __('sanabel.donation.transaction_ref'),
        ]);

        $donor = auth()->user()?->donor;

        if (! $donor) {
            $this->error = __('sanabel.donation.no_donor_account');

            return;
        }

        try {
            // No basket, so the service marks it general on its own. Passing
            // the designation here as well would be a second place for the
            // same decision to be made differently.
            app(PaymentGateway::class)->record([
                'donor_id' => $donor->id,
                'amount' => $this->amount,
                'transaction_ref' => $this->transactionRef,
                'route' => 'platform',
            ]);
        } catch (DuplicateTransactionRef $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->amount = null;
        $this->transactionRef = '';
        $this->notice = __('sanabel.public.transfer_recorded');
        $this->thanks = true;
    }

    public function render()
    {
        return view('livewire.general-donation', [
            'transferWallet' => app(TransferRouting::class)->wallet(),
            'hasDonorAccount' => (bool) auth()->user()?->donor,
        ])->layout('layouts.app');
    }
}
