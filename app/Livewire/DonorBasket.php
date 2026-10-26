<?php

namespace App\Livewire;

use App\Exceptions\DuplicateTransactionRef;
use App\Exceptions\ReservationUnavailable;
use App\Http\Resources\MaskedCaseResource;
use App\Models\Basket;
use App\Models\BasketItem;
use App\Models\Donation;
use App\Models\Setting;
use App\Payments\PaymentGateway;
use App\Services\BasketService;
use App\Services\CoverageService;
use App\Services\MediaService;
use App\Services\TransferRouting;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * T-19 and T-26 — the basket, its 24h hold, and recording the transfer.
 * The donor moves the money outside the platform; this screen records it.
 */
class DonorBasket extends Component
{
    use WithFileUploads;

    public ?string $error = null;

    public ?string $notice = null;

    /** Set the moment a transfer is recorded, cleared on the next action. */
    public bool $thanks = false;

    public string $transactionRef = '';

    /** T-123 — an image or a PDF of the transfer, optional. */
    public $receipt = null;

    /**
     * T-147 — «يكفي يظهر رقم الملف المبلغ المتبرع به مع الاستطاعه انه يخفض
     * المبلغ او يزيده مع نسبة التغطيه», 29 October.
     *
     * One amount per line, keyed by basket item id, so the donor changes what
     * they are giving where they are reading it. Kept in its own array rather
     * than on the item rows the view receives: the view's rows are masked
     * output, and this is an input.
     *
     * @var array<int,int|string|null>
     */
    public array $amounts = [];

    #[On('basket-updated')]
    public function refreshBasket(): void
    {
        //
    }

    /**
     * A typed amount, saved on the line it was typed on.
     *
     * The ceiling is the family's remaining need for the month this line
     * answers — the same figure the hold is checked against — so a line that
     * would be refused at confirmation is refused here, where it can still be
     * corrected. A refused amount is put back rather than left on screen.
     */
    public function updatedAmounts(mixed $value, string $key): void
    {
        $this->error = $this->notice = null;

        $item = $this->ownedItem((int) $key);

        if (! $item) {
            return;
        }

        $amount = (int) $value;
        $ceiling = $this->ceilingFor($item);

        if ($amount < 1 || $amount > $ceiling) {
            $this->amounts[$item->getKey()] = $item->amount;
            $this->error = $item->campaign_id
                ? __('sanabel.basket.exceeds_goal')
                : __('sanabel.basket.exceeds_remaining');

            return;
        }

        $item->forceFill(['amount' => $amount])->save();

        $this->notice = __('sanabel.public.amount_updated');
    }

    /** The most this line may hold: a campaign's remainder, or the family's. */
    private function ceilingFor(BasketItem $item): int
    {
        $coverage = app(CoverageService::class);

        if ($item->campaign) {
            return $coverage->campaignRemaining($item->campaign);
        }

        return $coverage->remainingNeed(
            $item->beneficiary,
            month: $item->coverage_month ?? $coverage->currentMonth(),
        );
    }

    /** A line of this donor's own basket, or nothing. */
    private function ownedItem(int $itemId): ?BasketItem
    {
        return BasketItem::query()
            ->with(['beneficiary', 'campaign'])
            ->whereKey($itemId)
            ->whereHas('basket', fn ($q) => $q->where('donor_id', auth()->user()->donor?->id))
            ->first();
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
            // T-123 — the slip for the transfer the donor has just made.
            // Optional, because the reference is what the finance desk
            // matches against the statement; the picture is what saves them
            // asking for it.
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:8192'],
        ], attributes: [
            'transactionRef' => __('sanabel.donation.transaction_ref'),
            'receipt' => __('sanabel.donation.receipt_upload'),
        ]);

        $basket = $this->basket();

        if ($basket->items()->count() === 0) {
            $this->error = __('sanabel.basket.empty');

            return;
        }

        try {
            $donation = app(PaymentGateway::class)->record([
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

        $this->attachReceipt($donation);

        $this->transactionRef = '';
        $this->receipt = null;
        $this->notice = __('sanabel.public.transfer_recorded');
        // Shown once, under the confirmation, in the association's own words.
        $this->thanks = true;
    }

    /**
     * T-123 — the slip, stored against the donation it belongs to.
     *
     * A refused file never costs the donor the transfer they have already
     * made: the donation is recorded either way and the screen says what
     * happened to the picture.
     */
    private function attachReceipt(Donation $donation): void
    {
        if (! $this->receipt instanceof TemporaryUploadedFile) {
            return;
        }

        try {
            $media = app(MediaService::class)
                ->store($this->receipt, $donation, 'receipt', auth()->user());
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $donation->forceFill(['receipt_media_id' => $media->getKey()])->save();
    }

    /**
     * T-67 — null for a signed-in account that has no donor record yet.
     *
     * currentFor() demands a Donor, and a user carrying the donor role does
     * not necessarily have the row: it is created when they first give. The
     * basket page therefore answered 500 for a donor who had never donated,
     * which is every donor on their first visit. The page now shows the empty
     * basket, which is the truth.
     */
    private function basket(): ?Basket
    {
        $donor = auth()->user()?->donor;

        return $donor ? app(BasketService::class)->currentFor($donor) : null;
    }

    public function render()
    {
        $basket = $this->basket();
        $items = $basket
            ? $basket->items()->with(['beneficiary', 'campaign'])->get()
            : collect();

        // The boxes read what is stored. A refused amount has already been put
        // back by updatedAmounts(), so this never overwrites what the donor is
        // still typing — and a line removed elsewhere leaves no box behind.
        $this->amounts = $items->mapWithKeys(fn (BasketItem $item) => [$item->getKey() => $item->amount])->all();

        return view('livewire.donor-basket', [
            'basket' => $basket,
            'items' => $items->map(fn (BasketItem $item) => [
                'ceiling' => $this->ceilingFor($item),
                'id' => $item->id,
                'amount' => $item->amount,
                // A campaign is published under its own title, so naming it
                // here reveals nothing the donor cannot already read. The
                // family behind it stays masked either way.
                'campaign' => $item->campaign?->title_ar,
                // The month the donor actually pledged for, not whichever is
                // open now: a basket held across the turn of the month would
                // otherwise show a card answering a different month (T-83).
                'case' => (new MaskedCaseResource(
                    $item->beneficiary,
                    month: $item->coverage_month,
                ))->resolve(),
            ]),
            'total' => $basket?->total() ?? 0,
            // T-78 — the basket's own switch for the sufficiency sentence,
            // separate from the one on the public list.
            'showSufficiencyNote' => (bool) Setting::value(
                'show_sufficiency_note_in_basket',
                config('sanabel.setting_defaults.show_sufficiency_note_in_basket')
            ),
            // One destination for everything in the basket.
            'transferWallet' => app(TransferRouting::class)->wallet(),
        ])->layout('layouts.app');
    }
}
