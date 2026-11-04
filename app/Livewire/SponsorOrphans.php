<?php

namespace App\Livewire;

use App\Models\Beneficiary;
use App\Services\SponsorshipService;
use Livewire\Component;

/**
 * T-246 — «وعند دخول المتبرع يظهر انه يوجد اكثر من يتيم ويستطيع تحديد كم
 * يتيم يحتاج أن يكفل», 3 November.
 *
 * One family, one card, and a number. The donor says how many orphans
 * they want to sponsor; which children those are is the association's to
 * decide and is never on the screen (rule 2). That is also why the card
 * carries a count and not three cards: three cards sharing one file
 * number would let any visitor count a household's orphans at a glance.
 *
 * Nothing here knows what a sponsorship costs or how long it runs. The
 * service reads the association's own rate (T-210), and the card above
 * this component prints it.
 */
class SponsorOrphans extends Component
{
    /*
     | The file number and not an id, because a masked card carries no id
     | and must not: `MaskedCaseResource::ALLOWED_KEYS` is the whole of what
     | a donor is ever handed, and every other donor action on this platform
     | is addressed the same way (T-24's basket among them).
     */
    public string $fileNumber;

    public int $available = 0;

    public int $count = 1;

    public ?string $error = null;

    public ?string $notice = null;

    public function mount(string $fileNumber, int $available): void
    {
        $this->fileNumber = $fileNumber;
        $this->available = $available;
    }

    public function sponsor(): void
    {
        $this->error = $this->notice = null;

        $donor = auth()->user()?->donor;

        if (! $donor) {
            // A sponsorship is a standing commitment with a donor's name on
            // it for a year. Unlike a one-off transfer, it cannot belong to
            // somebody the system has no way of reaching next month.
            $this->error = __('sanabel.sponsorships.needs_account');

            return;
        }

        $family = Beneficiary::where('file_number', $this->fileNumber)->first();

        if (! $family) {
            $this->error = __('sanabel.sponsorships.family_not_available');

            return;
        }

        try {
            $taken = app(SponsorshipService::class)
                ->sponsorOrphans($family, $donor, $this->count, auth()->user());
        } catch (\RuntimeException $e) {
            // Somebody may have taken the last one while this page was open.
            $this->available = $family->orphansAvailableForSponsorship()->count();
            $this->error = $e->getMessage();

            return;
        }

        $this->available = $family->orphansAvailableForSponsorship()->count();
        $this->count = min(1, max(0, $this->available));
        $this->notice = __('sanabel.sponsorships.taken', ['count' => $taken->count()]);
    }

    public function render()
    {
        return view('livewire.sponsor-orphans');
    }
}
