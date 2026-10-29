<?php

namespace App\Livewire;

use App\Models\Beneficiary;
use Livewire\Component;

/**
 * T-180 — the one button that keeps a family on the donor's own list.
 *
 * «المفضلة هي الحالات التي يتم حفظها من قبل المتبرع للعودة لها, وعند السداد
 * لهم تبقى في المفضلة للعودة لها متى ما شاء», 29 October.
 *
 * It takes a file number and never an identifier: a file number is what a
 * donor-facing screen carries, and it is the only handle `MaskedCaseResource`
 * hands out. So nothing on this path can be pointed at a family by guessing a
 * row id, and a published file is the only kind it can resolve.
 */
class FavouriteToggle extends Component
{
    public string $fileNumber;

    public bool $saved = false;

    public function mount(string $fileNumber): void
    {
        $this->fileNumber = $fileNumber;
        $this->saved = $this->isSaved();
    }

    public function toggle(): void
    {
        $donor = auth()->user()?->donor;
        $case = $this->case();

        if (! $donor || ! $case) {
            return;
        }

        /*
         | `toggle()` on the relation, so the pair is added or removed in one
         | statement and the unique index is what decides: two taps arriving
         | together cannot leave two rows behind.
         */
        $donor->favourites()->toggle($case->getKey());

        $this->saved = $this->isSaved();

        // The list page, when it is the one being looked at, redraws itself.
        $this->dispatch('favourites-changed');
    }

    private function isSaved(): bool
    {
        $donor = auth()->user()?->donor;
        $case = $this->case();

        return $donor && $case
            ? $donor->favourites()->whereKey($case->getKey())->exists()
            : false;
    }

    /** Published only: the browse list and the family page are the two screens this sits on. */
    private function case(): ?Beneficiary
    {
        return Beneficiary::query()
            ->published()
            ->where('file_number', $this->fileNumber)
            ->first();
    }

    public function render()
    {
        return view('livewire.favourite-toggle');
    }
}
