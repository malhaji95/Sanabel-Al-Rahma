<?php

namespace App\Livewire;

use App\Models\Beneficiary;
use App\Services\BasketService;
use Livewire\Component;

/** Adds one family to the donor's open basket. Reserving happens on the basket page. */
class AddToBasket extends Component
{
    public string $fileNumber;

    public int $remaining;

    public ?int $amount = null;

    // Named `notice`, not `message`: Blade's @error directive binds $message.
    public ?string $notice = null;

    public function mount(string $fileNumber, int $remaining): void
    {
        $this->fileNumber = $fileNumber;
        $this->remaining = $remaining;
        $this->amount = $remaining > 0 ? $remaining : null;
    }

    public function add(): void
    {
        $this->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:'.max(1, $this->remaining)],
        ], attributes: ['amount' => __('sanabel.public.amount')]);

        $donor = auth()->user()->donor;

        if (! $donor) {
            $this->notice = __('sanabel.public.donor_account_required');

            return;
        }

        // Looked up by file number: the browse screen never holds a database id.
        $case = Beneficiary::published()->where('file_number', $this->fileNumber)->firstOrFail();

        $basket = app(BasketService::class)->openFor($donor);
        app(BasketService::class)->addItem($basket, $case, (int) $this->amount);

        $this->notice = __('sanabel.public.added_to_basket');
        $this->dispatch('basket-updated');
    }

    public function render()
    {
        return view('livewire.add-to-basket');
    }
}
