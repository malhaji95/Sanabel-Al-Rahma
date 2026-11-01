<?php

namespace App\Livewire;

use App\Models\Beneficiary;
use App\Services\BasketService;
use App\Services\CoverageService;
use Illuminate\Support\Carbon;
use Livewire\Component;

/** Adds one family to the donor's open basket. Reserving happens on the basket page. */
class AddToBasket extends Component
{
    public string $fileNumber;

    public int $remaining;

    /** The stage being funded now, which is what the box offers by default. */
    public int $targetRemaining = 0;

    public ?int $amount = null;

    /** The month this pledge answers, as Y-m. */
    public string $coverageMonth = '';

    /** @var array<string,string> */
    public array $openMonths = [];

    // Named `notice`, not `message`: Blade's @error directive binds $message.
    public ?string $notice = null;

    /**
     * T-147 — «المفروض عند اضافة اي مبلغ للسله يضل بنفس المكان مع اعطاءه
     * خيار مواصلة التبرع او اتمام عملية التبرع», 29 October.
     *
     * The box stays where it is and offers the two ways on. Nothing is decided
     * for the donor: the page does not move, and neither choice is taken until
     * it is clicked.
     */
    public bool $added = false;

    public function mount(
        string $fileNumber,
        int $remaining,
        int $targetRemaining = 0,
        ?string $coverageMonth = null,
    ): void {
        $this->fileNumber = $fileNumber;
        $this->remaining = $remaining;
        $this->targetRemaining = $targetRemaining;

        /*
         | T-78 — the box opens on the current stage rather than the whole
         | month, because that is what the association is asking for now. The
         | donor may still type the full month's need: the ceiling below is the
         | real remaining need, not the target.
         */
        $offer = $targetRemaining > 0 ? $targetRemaining : $remaining;

        $this->amount = $offer > 0 ? $offer : null;

        /*
         | The current month, plus the next one once it opens — which is what
         | keeps a family from falling into a gap at the turn of the month.
         |
         | T-83 — the card says which month it is asking for, and the box
         | starts there. Earlier months are left out rather than shown and
         | refused: the figures beside the box answer the card's month, and a
         | month already covered has nothing left for a reservation to claim.
         */
        $months = collect(app(CoverageService::class)->openMonths())
            ->mapWithKeys(fn ($month) => [$month->format('Y-m') => $month->translatedFormat('F Y')]);

        $this->coverageMonth = $coverageMonth !== null && $months->has($coverageMonth)
            ? $coverageMonth
            : (string) $months->keys()->first();

        $this->openMonths = $months
            ->filter(fn ($label, $key) => $key >= $this->coverageMonth)
            ->all();
    }

    /** The month the box is asking for, written out. */
    public function monthLabel(): string
    {
        return $this->openMonths[$this->coverageMonth] ?? '';
    }

    public function add(): void
    {
        $this->validate([
            'amount' => ['required', 'integer', 'min:1'],
        ], attributes: ['amount' => __('sanabel.public.amount')]);

        /*
         | T-220 — «حساب عيلة وبدها تتبرع؟ هاد الخيار مرفوض وغير ممكن»,
         | 1 November. The box is not drawn for a household's account, and
         | the refusal is here too: a screen that does not offer something
         | is not the same as a path that will not do it.
         */
        if (auth()->user()->isFamily()) {
            $this->notice = __('sanabel.public.family_may_not_give');

            return;
        }

        $donor = auth()->user()->donor;

        if (! $donor) {
            $this->notice = __('sanabel.public.donor_account_required');

            return;
        }

        // Looked up by file number: the browse screen never holds a database id.
        $case = Beneficiary::published()->where('file_number', $this->fileNumber)->firstOrFail();

        $month = Carbon::createFromFormat('Y-m', $this->coverageMonth)->startOfMonth();

        /*
         | T-201 — a donor may offer more than the family is short, and the
         | family is credited with what it is short and not a pound more.
         |
         | The ceiling is read here rather than from `$this->remaining`: that
         | property travels to the browser and back, so it is the donor's to
         | edit. The reservation still checks it again under a row lock in
         | `BasketService::reserve()` (rule 6); this is the courtesy, that is
         | the guard.
         */
        $takeable = app(CoverageService::class)->remainingNeed($case, month: $month);
        $amount = min((int) $this->amount, max(0, $takeable));

        if ($amount < 1) {
            $this->notice = __('sanabel.public.fully_covered', ['month' => $this->monthLabel()]);

            return;
        }

        $basket = app(BasketService::class)->openFor($donor);
        app(BasketService::class)->addItem($basket, $case, $amount, $month);

        $this->notice = __('sanabel.public.added_to_basket');
        $this->added = true;
        $this->dispatch('basket-updated');
    }

    /** «مواصلة التبرع» — puts the box back without leaving the list. */
    public function keepGiving(): void
    {
        $this->added = false;
        $this->notice = null;
    }

    public function render()
    {
        return view('livewire.add-to-basket');
    }
}
