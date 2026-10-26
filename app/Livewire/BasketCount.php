<?php

namespace App\Livewire;

use App\Services\BasketService;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * T-147 — the basket, counted where the donor can see it.
 *
 * «يظهر عندي بالسلة اني تم التبرع لحالة … موجود بالسلة منتج ولكنه ما يظهر شي»,
 * 29 October. The basket lived behind the account menu, so after an addition
 * nothing on the page changed. This is the shop's own answer: a number beside
 * the basket, which moves the moment something is put in it.
 *
 * It counts and does not create: a donor who has never given still has no
 * basket row after reading a page.
 */
class BasketCount extends Component
{
    #[On('basket-updated')]
    public function refreshCount(): void
    {
        // The re-render is the point; the listener has nothing else to do.
    }

    public function render()
    {
        $donor = auth()->user()?->donor;

        return view('livewire.basket-count', [
            'count' => $donor
                ? (int) app(BasketService::class)->existingFor($donor)?->items()->count()
                : 0,
        ]);
    }
}
