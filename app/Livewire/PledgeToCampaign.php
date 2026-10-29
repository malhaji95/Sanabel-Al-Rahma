<?php

namespace App\Livewire;

use App\Models\Campaign;
use App\Services\BasketService;
use App\Services\CoverageService;
use Livewire\Component;

/**
 * Adds one campaign to the donor's open basket. The same basket and the same
 * 24h hold as a pledge to a family; reserving still happens on the basket page.
 */
class PledgeToCampaign extends Component
{
    public int $campaignId;

    public int $remaining;

    public ?int $amount = null;

    // Named `notice`, not `message`: Blade's @error directive binds $message.
    public ?string $notice = null;

    /*
     | T-190 — «المشكلة التي كانت بالحالة بعدها موجودة بالحملات, نحتاجها تُطبَّق
     | بكل شيء فيه دفع بالمنصة: عند الدفع يظهر لك مواصلة العطاء أو إتمام
     | التبرع», 29 October.
     |
     | T-147 answered it for a family: the page does not move, and the donor
     | is offered the two ways on rather than having one taken for them. A
     | campaign was left with a line of text and no way forward, so a donor who
     | had just pledged had to find the basket themselves.
     */
    public bool $added = false;

    public function mount(Campaign $campaign): void
    {
        $this->campaignId = $campaign->getKey();
        $this->remaining = app(CoverageService::class)->campaignRemaining($campaign);
        $this->amount = $this->remaining > 0 ? min($this->remaining, 50_000) : null;
    }

    public function pledge(): void
    {
        $this->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:'.max(1, $this->remaining)],
        ], attributes: ['amount' => __('sanabel.public.amount')]);

        $donor = auth()->user()?->donor;

        if (! $donor) {
            $this->notice = __('sanabel.public.donor_account_required');

            return;
        }

        $campaign = Campaign::where('is_published', true)->findOrFail($this->campaignId);

        $basket = app(BasketService::class)->openFor($donor);
        app(BasketService::class)->addCampaign($basket, $campaign, (int) $this->amount);

        $this->notice = __('sanabel.public.added_to_basket');
        $this->added = true;
        $this->dispatch('basket-updated');
    }

    /** T-190 — back to the form, on this page, with nothing lost. */
    public function keepGiving(): void
    {
        $this->added = false;
        $this->notice = null;
    }

    public function render()
    {
        return view('livewire.pledge-to-campaign');
    }
}
