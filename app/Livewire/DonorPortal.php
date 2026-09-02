<?php

namespace App\Livewire;

use App\Http\Resources\MaskedCaseResource;
use App\Models\Donation;
use Livewire\Component;

/** T-26 — my donations and my badge. Even here, cases are masked. */
class DonorPortal extends Component
{
    public function render()
    {
        $donor = auth()->user()->donor;

        $donations = $donor
            ? $donor->donations()->with('allocations.beneficiary')->latest('id')->take(50)->get()
            : collect();

        return view('livewire.donor-portal', [
            'donor' => $donor,
            'donations' => $donations->map(fn (Donation $donation) => [
                'transaction_ref' => $donation->transaction_ref,
                'amount' => $donation->amount,
                'status' => $donation->status,
                'status_label' => __('sanabel.donations.'.$donation->status),
                'created_at' => $donation->created_at->translatedFormat('Y-m-d'),
                'cases' => $donation->allocations
                    ->filter(fn ($a) => $a->beneficiary !== null)
                    ->map(fn ($a) => (new MaskedCaseResource($a->beneficiary))->resolve()['file_number'])
                    ->values(),
            ]),
        ])->layout('layouts.app');
    }
}
