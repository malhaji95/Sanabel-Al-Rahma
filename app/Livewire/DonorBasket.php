<?php

namespace App\Livewire;

use App\Exceptions\DuplicateTransactionRef;
use App\Exceptions\ReservationUnavailable;
use App\Http\Resources\MaskedCaseResource;
use App\Models\BasketItem;
use App\Payments\PaymentGateway;
use App\Services\BasketService;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * T-19 and T-26 — the basket, its 24h hold, and recording the transfer.
 * The donor moves the money outside the platform; this screen records it.
 */
class DonorBasket extends Component
{
    public ?string $error = null;

    public ?string $notice = null;

    public string $transactionRef = '';

    #[On('basket-updated')]
    public function refreshBasket(): void
    {
        //
    }

    public function removeItem(int $itemId): void
    {
        BasketItem::query()
            ->whereKey($itemId)
            ->whereHas('basket', fn ($q) => $q->where('donor_id', auth()->user()->donor?->id))
            ->delete();
    }

    public function reserve(): void
    {
        $this->error = $this->notice = null;

        try {
            $basket = app(BasketService::class)->reserve($this->basket());
        } catch (ReservationUnavailable $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->notice = __('sanabel.public.reserved_until', [
            'time' => $basket->reserved_until->translatedFormat('Y-m-d H:i'),
        ]);
    }

    /** Records the transfer the donor says they have already made. */
    public function recordTransfer(): void
    {
        $this->error = $this->notice = null;

        $this->validate([
            'transactionRef' => ['required', 'string', 'max:191'],
        ], attributes: ['transactionRef' => __('sanabel.donation.transaction_ref')]);

        $basket = $this->basket();

        if ($basket->items()->count() === 0) {
            $this->error = __('sanabel.basket.empty');

            return;
        }

        try {
            app(PaymentGateway::class)->record([
                'donor_id' => auth()->user()->donor->id,
                'amount' => $basket->total(),
                'transaction_ref' => $this->transactionRef,
                'basket_id' => $basket->id,
                'route' => 'platform',
            ]);
        } catch (DuplicateTransactionRef $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->transactionRef = '';
        $this->notice = __('sanabel.public.transfer_recorded');
    }

    private function basket()
    {
        return app(BasketService::class)->currentFor(auth()->user()->donor);
    }

    public function render()
    {
        $basket = $this->basket();
        $items = $basket->items()->with('beneficiary')->get();

        return view('livewire.donor-basket', [
            'basket' => $basket,
            'items' => $items->map(fn (BasketItem $item) => [
                'id' => $item->id,
                'amount' => $item->amount,
                'case' => (new MaskedCaseResource($item->beneficiary))->resolve(),
            ]),
            'total' => $basket->total(),
        ])->layout('layouts.app');
    }
}
