<?php

namespace App\Livewire;

use App\Http\Resources\MaskedCaseResource;
use App\Models\Beneficiary;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * T-180 — the families this donor kept, to come back to.
 *
 * Masked like every other donor-facing list, through the one resource rule 2
 * admits: a file number, an area, bands and labels. Keeping a family gives the
 * donor no more of it than browsing did.
 *
 * A file that has since been withdrawn, closed or anonymised is simply not
 * drawn: the row stays on the donor's list so that the family comes back if
 * the file is published again, and in the meantime the page says how many are
 * resting rather than pretending the list is shorter than it is.
 */
class DonorFavourites extends Component
{
    #[On('favourites-changed')]
    public function refreshList(): void
    {
        // The attribute is the whole of it: Livewire redraws on the event.
    }

    public function render()
    {
        $donor = auth()->user()?->donor;

        $kept = $donor
            ? $donor->favourites()->get()
            : collect();

        $shown = $kept->filter(fn (Beneficiary $case) => $case->status === 'published');

        return view('livewire.donor-favourites', [
            'donor' => $donor,
            'cases' => $shown->map(fn (Beneficiary $case) => (new MaskedCaseResource($case))->resolve())->values(),
            // Kept, but not on a published file today.
            'restingCount' => $kept->count() - $shown->count(),
        ])->layout('layouts.app');
    }
}
