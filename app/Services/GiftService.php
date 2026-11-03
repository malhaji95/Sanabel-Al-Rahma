<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Gift;
use App\Models\GiftCard;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * T-241 — «باب الهدايا», 3 November, step for step off the association's
 * own reference.
 *
 * A gift is not a new kind of money. It is a platform donation with one or
 * more cards hanging off it, so rule 1 holds it, the finance desk verifies
 * it like any other transfer, and a gift aimed at a project allocates to
 * that campaign the moment it is verified.
 *
 * What this service owns is the three rules that are the gift's own:
 *
 *   · the cards against a transfer add up to the transfer. A card for a
 *     sum nobody sent is a promise the association did not make;
 *   · a card is issued only after the money is verified (requirement 9),
 *     and not before its hour if the donor chose one (requirement 8);
 *   · the card is read at a private address and shows the amount only
 *     where the donor said to (requirement 6).
 *
 * How the card reaches the recipient in phase one: the donor sends it.
 * WhatsApp and SMS both need an account with an outside provider, approved
 * templates and credit, none of which the association has yet, so the
 * system issues the card at its address and hands the donor a ready
 * message to their own WhatsApp. Everything an automatic channel would
 * need is already on the row — the number, the country key, the hour — so
 * the day they open an account it is a sender beside `issueDue()` and not
 * a rebuild.
 */
class GiftService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** The ready amounts the screen offers. Data, so the association moves them. */
    public static function amounts(): array
    {
        return array_values(array_filter(array_map(
            fn ($amount) => (int) $amount,
            (array) Setting::value('gift_amounts', config('sanabel.setting_defaults.gift_amounts')),
        ), fn (int $amount) => $amount > 0));
    }

    /**
     * Writes the cards of one transfer.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function attach(Donation $donation, array $rows): Collection
    {
        if ($rows === []) {
            throw new \RuntimeException(__('sanabel.gift.no_cards'));
        }

        $total = array_sum(array_map(fn ($row) => (int) ($row['amount'] ?? 0), $rows));

        if ($total !== (int) $donation->amount) {
            throw new \RuntimeException(__('sanabel.gift.cards_do_not_add_up'));
        }

        return DB::transaction(function () use ($donation, $rows) {
            return collect($rows)->map(function (array $row) use ($donation) {
                $card = GiftCard::published()->find($row['gift_card_id'] ?? null);

                if (! $card) {
                    throw new \RuntimeException(__('sanabel.gift.card_not_offered'));
                }

                $campaignId = $row['campaign_id'] ?? null;

                if ($campaignId && ! Campaign::where('is_published', true)->whereKey($campaignId)->exists()) {
                    throw new \RuntimeException(__('sanabel.gift.project_not_offered'));
                }

                $sendAt = filled($row['send_at'] ?? null) ? Carbon::parse($row['send_at']) : null;

                // A card scheduled for a moment already past is a card the
                // donor meant to send now.
                if ($sendAt && $sendAt->isPast()) {
                    $sendAt = null;
                }

                return Gift::create([
                    'donation_id' => $donation->getKey(),
                    'gift_card_id' => $card->getKey(),
                    'campaign_id' => $campaignId,
                    'giver_name_ar' => trim((string) $row['giver_name_ar']),
                    'recipient_name_ar' => trim((string) $row['recipient_name_ar']),
                    'recipient_country_code' => trim((string) $row['recipient_country_code']),
                    'recipient_phone_encrypted' => preg_replace('/\D/', '', (string) $row['recipient_phone']),
                    'show_amount' => (bool) ($row['show_amount'] ?? true),
                    'amount' => (int) $row['amount'],
                    'currency' => $donation->currency ?? config('sanabel.currency'),
                    'send_at' => $sendAt,
                ]);
            });
        });
    }

    /**
     * Issues every card that is due: its transfer verified, and its hour
     * either unset or past.
     *
     * Called on verification for the cards of that transfer, and once a
     * quarter of an hour by the scheduler for the ones with an hour on them.
     */
    public function issueDue(?Donation $donation = null): int
    {
        $query = Gift::query()
            ->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('send_at')->orWhere('send_at', '<=', now()))
            ->whereHas('donation', fn ($q) => $q->where('status', 'verified'));

        if ($donation) {
            $query->where('donation_id', $donation->getKey());
        }

        $issued = 0;

        foreach ($query->with('donation.donor')->get() as $gift) {
            $gift->forceFill(['status' => 'issued', 'issued_at' => now()])->save();

            // The donor is told their card is ready, because in phase one
            // the donor is the channel. Rule 10 — a reference, never the
            // recipient's name or number.
            $this->notifications->send(
                $gift->donation?->donor?->user_id,
                'gift_card_ready',
                ['ref' => $gift->token],
            );

            $issued++;
        }

        return $issued;
    }

    /** Where the card is read. */
    public function cardUrl(Gift $gift): string
    {
        return route('gift.card', ['token' => $gift->token]);
    }

    /**
     * The donor's own WhatsApp, prefilled. No account, no provider, no cost:
     * the message leaves the donor's phone, which is also why the recipient
     * sees it come from somebody they know.
     */
    public function shareLink(Gift $gift): string
    {
        $text = __('sanabel.gift.share_text', [
            'recipient' => $gift->recipient_name_ar,
            'url' => $this->cardUrl($gift),
        ]);

        return 'https://wa.me/'.$gift->dialNumber().'?text='.rawurlencode($text);
    }
}
