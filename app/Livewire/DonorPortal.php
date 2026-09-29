<?php

namespace App\Livewire;

use App\Exceptions\DuplicateTransactionRef;
use App\Http\Resources\MaskedCaseResource;
use App\Models\Donation;
use App\Payments\PaymentGateway;
use App\Services\TransferRouting;
use Livewire\Component;

/**
 * T-26 — my donations, my badge, and a general donation.
 *
 * A general donation names no family: it reaches the association's wallet and
 * the association decides where it goes. The earmarked kind goes through the
 * basket instead, on its own screen.
 */
class DonorPortal extends Component
{
    public ?int $generalAmount = null;

    public string $generalRef = '';

    public ?string $error = null;

    public ?string $notice = null;

    public function recordGeneral(): void
    {
        $this->error = $this->notice = null;

        $this->validate([
            'generalAmount' => ['required', 'integer', 'min:1'],
            'generalRef' => ['required', 'string', 'max:191'],
        ], attributes: [
            'generalAmount' => __('sanabel.public.amount'),
            'generalRef' => __('sanabel.donation.transaction_ref'),
        ]);

        $donor = auth()->user()->donor;

        if (! $donor) {
            $this->error = __('sanabel.public.donor_account_required');

            return;
        }

        try {
            app(PaymentGateway::class)->record([
                'donor_id' => $donor->id,
                'amount' => (int) $this->generalAmount,
                'transaction_ref' => $this->generalRef,
                'designation' => 'general',
            ]);
        } catch (DuplicateTransactionRef $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->generalAmount = null;
        $this->generalRef = '';
        $this->notice = __('sanabel.public.transfer_recorded');
    }

    public function render()
    {
        $donor = auth()->user()->donor;

        $donations = $donor
            ? $donor->donations()->with('allocations.beneficiary')->latest('id')->take(50)->get()
            : collect();

        return view('livewire.donor-portal', [
            'donor' => $donor,
            // The same wallet the basket sends earmarked money to.
            'generalRoute' => app(TransferRouting::class)->wallet(),
            'donations' => $donations->map(fn (Donation $donation) => [
                'transaction_ref' => $donation->transaction_ref,
                'amount' => $donation->amount,
                'status' => $donation->status,
                'status_label' => __('sanabel.donations.'.$donation->status),
                'designation' => $donation->designation,
                'designation_label' => __('sanabel.designation.'.$donation->designation),
                'created_at' => $donation->created_at->translatedFormat('Y-m-d'),
                'cases' => $donation->allocations
                    ->filter(fn ($a) => $a->beneficiary !== null)
                    ->map(fn ($a) => (new MaskedCaseResource($a->beneficiary))->resolve()['file_number'])
                    ->values(),
            ]),
        ])->layout('layouts.app');
    }
}
