<?php

namespace App\Livewire;

use App\Exceptions\DuplicateTransactionRef;
use App\Models\Campaign;
use App\Models\Donor;
use App\Models\GiftCard;
use App\Payments\PaymentGateway;
use App\Services\GiftService;
use App\Services\IdentityFormat;
use App\Services\MediaService;
use App\Services\TransferRouting;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * T-242 — the page a donor fills in to give a gift.
 *
 * The other half of T-241, and the half the association actually watched:
 * their ten steps on one screen, in their order. General or for a project,
 * a design out of theirs, the two names, the number with its country key,
 * a ready amount or one typed, the amount shown or hidden, another card if
 * they want one, now or at an hour — and then the transfer, which is the
 * same transfer every other donation on this platform is.
 *
 * It is `GeneralDonation` with cards on it, deliberately: the money path
 * has one shape and a second one would eventually disagree with it. A
 * guest may give, because T-108 settled that a gift given without signing
 * in still belongs to somebody.
 *
 * The cards are not issued here. The finance desk verifies the transfer
 * first (T-241, requirement 9) and the donor is told the moment it is.
 */
class GiveAgift extends Component
{
    use WithFileUploads;

    /** One row per card. «إمكانية إضافة أكثر من بطاقة إهداء ضمن العملية نفسها». */
    public array $cards = [];

    public string $transactionRef = '';

    /** Asked of a guest only; a signed-in donor has both on file already. */
    public string $name = '';

    public string $phone = '';

    public $receipt = null;

    public ?string $notice = null;

    /** T-262 — where this donor reads the receipt for what they just gave. */
    public ?string $receiptUrl = null;

    public ?string $error = null;

    public bool $thanks = false;

    public function mount(): void
    {
        $this->cards = [$this->blankCard()];
    }

    private function blankCard(): array
    {
        return [
            'kind' => 'general',
            'campaign_id' => null,
            'gift_card_id' => GiftCard::published()->value('id'),
            'giver_name_ar' => '',
            'recipient_name_ar' => '',
            'recipient_country_code' => config('sanabel.gift_default_country_code'),
            'recipient_phone' => '',
            'amount' => null,
            'show_amount' => true,
            'schedule' => false,
            'send_at' => null,
        ];
    }

    public function addCard(): void
    {
        $this->cards[] = $this->blankCard();
    }

    public function removeCard(int $index): void
    {
        // Never down to nothing: a gift with no card is not a gift.
        if (count($this->cards) <= 1) {
            return;
        }

        unset($this->cards[$index]);
        $this->cards = array_values($this->cards);
    }

    /** Whether any card on the page is waiting for an hour. */
    public function hasAschedule(): bool
    {
        return collect($this->cards)->contains(fn ($card) => (bool) ($card['schedule'] ?? false));
    }

    /** What the donor is being asked to transfer: the cards added up. */
    public function getTotalProperty(): int
    {
        return (int) collect($this->cards)->sum(fn ($card) => max(0, (int) ($card['amount'] ?? 0)));
    }

    public function record(): void
    {
        $this->error = $this->notice = null;
        $this->receiptUrl = null;
        $this->thanks = false;

        $signedIn = auth()->user()?->donor;

        $this->validate(array_filter([
            'cards' => ['required', 'array', 'min:1'],
            'cards.*.gift_card_id' => ['required', 'integer'],
            'cards.*.giver_name_ar' => ['required', 'string', 'min:2', 'max:191'],
            'cards.*.recipient_name_ar' => ['required', 'string', 'min:2', 'max:191'],
            'cards.*.recipient_country_code' => ['required', 'string', 'max:8'],
            'cards.*.recipient_phone' => ['required', 'string', 'min:6', 'max:20'],
            'cards.*.amount' => ['required', 'integer', 'min:1'],
            'cards.*.campaign_id' => ['nullable', 'integer'],
            'cards.*.send_at' => ['nullable', 'date'],
            'transactionRef' => ['required', 'string', 'max:191'],
            'name' => $signedIn ? null : ['required', 'string', 'min:3', 'max:191'],
            'phone' => $signedIn ? null : ['nullable', 'string', 'digits:'.IdentityFormat::PHONE_DIGITS],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:8192'],
        ]), attributes: [
            'cards.*.gift_card_id' => __('sanabel.gift.card'),
            'cards.*.giver_name_ar' => __('sanabel.gift.giver'),
            'cards.*.recipient_name_ar' => __('sanabel.gift.recipient'),
            'cards.*.recipient_country_code' => __('sanabel.gift.country_code'),
            'cards.*.recipient_phone' => __('sanabel.gift.phone'),
            'cards.*.amount' => __('sanabel.gift.amount'),
            'cards.*.send_at' => __('sanabel.gift.send_at'),
            'transactionRef' => __('sanabel.donation.transaction_ref'),
            'name' => __('sanabel.donation.donor'),
            'phone' => __('sanabel.donor.phone'),
            'receipt' => __('sanabel.donation.receipt_upload'),
        ]);

        /*
         | T-244 — «إذا المتبرع هو اللي بيرسل البطاقة من هاتفه، شو بيصير لما
         | يوصل موعد الإهداء؟ هل بيوصل له تذكير؟», 4 November.
         |
         | Yes: the card is issued at its hour and the donor is told the
         | moment it is. But the telling goes to an account — in-app and
         | email are the only channels this system has — and a guest has
         | none, so a guest who scheduled a card would be promised a
         | reminder that had nowhere to arrive.
         |
         | So scheduling asks for an account and giving does not. A gift
         | sent now needs no reminder: the donor is standing on the page.
         */
        if (! $signedIn && $this->hasAschedule()) {
            $this->error = __('sanabel.gift.schedule_needs_account');

            return;
        }

        // The same limit the general page carries, for the same reason: a
        // verifier reading invented references is not reading the real ones.
        if (RateLimiter::tooManyAttempts($key = 'donate:'.request()->ip(), 10)) {
            $this->error = __('sanabel.donation.too_many');

            return;
        }

        RateLimiter::hit($key, 3600);

        $donor = $signedIn ?: $this->guestDonor();

        try {
            $donation = app(PaymentGateway::class)->record([
                'donor_id' => $donor->id,
                'amount' => $this->total,
                'transaction_ref' => $this->transactionRef,
                'route' => 'platform',
            ]);
        } catch (DuplicateTransactionRef $e) {
            $this->error = $e->getMessage();

            return;
        }

        try {
            app(GiftService::class)->attach($donation, $this->rows());
        } catch (\RuntimeException $e) {
            // The cards are the point of the page, so a transfer that could
            // not carry them is not left sitting in the verifier's queue
            // pretending to be an ordinary donation.
            $donation->delete();
            $this->error = $e->getMessage();

            return;
        }

        if ($this->receipt instanceof TemporaryUploadedFile) {
            try {
                $media = app(MediaService::class)
                    ->store($this->receipt, $donation, 'receipt', auth()->user());

                $donation->forceFill(['receipt_media_id' => $media->getKey()])->save();
            } catch (\RuntimeException $e) {
                // The money has already moved; the picture is not worth
                // losing the record of it over.
                $this->error = $e->getMessage();
            }
        }

        $this->cards = [$this->blankCard()];
        $this->transactionRef = '';
        $this->receipt = null;
        $this->notice = __('sanabel.gift.recorded');
        // T-262 — a gift is a donation too, and its giver gets the same receipt.
        $this->receiptUrl = route('donation.receipt', ['token' => $donation->receipt_token]);
        $this->thanks = true;
    }

    /** The rows as the service wants them, with the choices resolved. */
    private function rows(): array
    {
        return collect($this->cards)->map(fn (array $card) => [
            'gift_card_id' => $card['gift_card_id'],
            // «إهداء عام أو إهداء مخصص لمشروع» — the project is read only
            // where they chose a project, so a campaign left behind by a
            // change of mind is not earmarked by accident.
            'campaign_id' => ($card['kind'] ?? 'general') === 'project' ? $card['campaign_id'] : null,
            'giver_name_ar' => $card['giver_name_ar'],
            'recipient_name_ar' => $card['recipient_name_ar'],
            'recipient_country_code' => $card['recipient_country_code'],
            'recipient_phone' => $card['recipient_phone'],
            'show_amount' => (bool) ($card['show_amount'] ?? true),
            'amount' => (int) $card['amount'],
            'send_at' => ($card['schedule'] ?? false) ? ($card['send_at'] ?: null) : null,
        ])->all();
    }

    /** The donor record behind a signed-out gift (T-108). */
    private function guestDonor(): Donor
    {
        $phone = (string) IdentityFormat::digits($this->phone);

        if ($phone === '') {
            return Donor::create(['name_ar' => trim($this->name)]);
        }

        $hash = Donor::hashPhone($phone);

        return Donor::firstOrCreate(
            ['phone_hash' => $hash],
            ['name_ar' => trim($this->name), 'phone_encrypted' => $phone],
        );
    }

    public function render()
    {
        return view('livewire.give-a-gift', [
            'designs' => GiftCard::published()->get(),
            'projects' => Campaign::where('is_published', true)
                ->orderByDesc('id')->pluck('title_ar', 'id'),
            'amounts' => GiftService::amounts(),
            'transferWallet' => app(TransferRouting::class)->wallet(),
        ])->layout('layouts.app');
    }
}
