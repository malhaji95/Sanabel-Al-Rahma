<?php

namespace App\Http\Controllers;

use App\Http\Resources\MaskedCaseResource;
use App\Models\Banner;
use App\Models\Beneficiary;
use App\Models\Campaign;
use App\Models\ContentCard;
use App\Models\Donation;
use App\Models\Gift;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Services\CoverageService;
use App\Services\DonorCaseList;
use App\Services\PlatformStats;
use App\Services\QrCode;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/** T-36 — the public site, driven entirely by CMS rows an admin edits. */
class PublicController extends Controller
{
    public function __construct(
        private readonly CoverageService $coverage,
    ) {}

    public function home(): View
    {
        // The three most urgent published cases, masked like every donor view
        // and held for a minute like the rest of the list (T-87).
        $urgent = collect(
            app(DonorCaseList::class)->page('monthly', null, 1, 3, withCovered: false)['cards']
        );

        $campaigns = Campaign::where('is_published', true)
            ->where('status', 'active')
            ->with('beneficiary')
            ->orderByDesc('id')->take(3)->get();

        /*
         | T-89 — every band of the page is a row the association edits, and
         | the page draws whatever it finds. One query for all of them: four
         | bands of a handful of cards each.
         */
        $cards = ContentCard::published()
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->groupBy('block');

        return view('public.home', [
            // The hero slides. Banners already carry a picture, a title, a
            // line and a destination, so the carousel is what they were for.
            'banners' => Banner::where('is_published', true)->orderBy('sort_order')->get(),
            'posts' => Post::where('is_published', true)->orderByDesc('published_at')->take(2)->get(),
            'campaigns' => $campaigns,
            'monthlyFunding' => $this->monthlyFunding($campaigns),
            'cases' => $urgent,
            // T-228 — whether there is a sponsorships page to send anybody
            // to. Empty when the association has closed the words, or when
            // no published file carries one of the kinds.
            'sponsorshipDoors' => app(DonorCaseList::class)->sponsorshipDoors(),
            'stats' => app(PlatformStats::class)->all(),
            'features' => $cards->get('feature', collect()),
            'tiles' => $cards->get('tile', collect()),
            'advantages' => $cards->get('advantage', collect()),
            // T-99 — at the foot of the page only, and absent until they add one.
            'partners' => $cards->get('partner', collect()),
            // The two cards at the foot of the page: the association's own
            // words, on pages it already edits.
            'about' => Page::where('slug', 'about')->where('is_published', true)->first(),
            'shariaBoard' => Page::where('slug', 'sharia-board')->where('is_published', true)->first(),
        ]);
    }

    public function page(string $slug): View
    {
        $page = Page::where('slug', $slug)->where('is_published', true)->firstOrFail();

        return view('public.page', [
            'page' => $page,
            // T-113 — loaded here rather than from the template, so the page
            // costs one more query however many sections it carries.
            'sections' => $page->publishedSections()->get(),
        ]);
    }

    public function news(): View
    {
        return view('public.news', [
            'posts' => Post::where('is_published', true)->orderByDesc('published_at')->paginate(10),
        ]);
    }

    public function post(string $slug): View
    {
        return view('public.post', [
            'post' => Post::where('slug', $slug)->where('is_published', true)->firstOrFail(),
        ]);
    }

    /**
     * The same article page, for a piece that has not gone out yet. Only an
     * account that may manage content gets it, so a draft is not readable by
     * anyone who guesses the slug.
     */
    public function previewPost(Request $request, string $slug)
    {
        abort_unless($request->user()?->can_('manage_cms'), 403, __('sanabel.permissions.denied'));

        return view('public.post', [
            'post' => Post::where('slug', $slug)->firstOrFail(),
            'isPreview' => true,
        ]);
    }

    /**
     * One published family file, as a donor sees it: the same masked card the
     * list draws, on an address that can be shared. A file that is covered or
     * no longer published still answers, saying so and offering others, rather
     * than taking a donation it cannot use.
     */
    public function case(string $fileNumber): View
    {
        $case = Beneficiary::published()
            ->with(['region', 'members', 'housing', 'healthRecords', 'association', 'debts', 'assets', 'livestock'])
            ->where('file_number', $fileNumber)
            ->first();

        $coverage = app(CoverageService::class);

        /*
         | T-83 — a shared link whose month has just been answered is not a
         | dead end any more: once the next month opens, the page offers that
         | month instead. It closes only when there is no open month left to
         | fund, or when what is left of the one there is is already held.
         */
        $month = $case ? $coverage->fundableMonth($case) : null;
        $closed = $case === null
            || $month === null
            || $coverage->remainingNeed($case, month: $month) <= 0;

        return view('public.case', [
            'card' => $case ? (new MaskedCaseResource($case, month: $month))->resolve() : null,
            'closed' => $closed,
            // Something else to give to, so a finished link is not a dead end.
            // The same three the home page shows, from the same held list.
            'alternatives' => $closed
                ? collect(app(DonorCaseList::class)->page('monthly', null, 1, 3, withCovered: false)['cards'])
                : collect(),
        ]);
    }

    public function campaigns(): View
    {
        $campaigns = Campaign::where('is_published', true)
            ->whereIn('status', ['active', 'funded', 'awaiting_execution'])
            ->with('beneficiary')
            ->orderByDesc('id')
            ->paginate(12);

        return view('public.campaigns', [
            'campaigns' => $campaigns,
            'monthlyFunding' => $this->monthlyFunding($campaigns->getCollection()),
        ]);
    }

    /**
     * One campaign, with room for the whole of what the association wrote.
     *
     * T-117 — the card clamps the text to three lines, because a card must.
     * So the story had nowhere to be read: the association could type it and
     * no donor could reach past the third line of it. The page draws the same
     * card with the clamp off, which is why a figure on it can never disagree
     * with the figure on the list.
     *
     * An unpublished campaign is not here. A closed one is: a link that has
     * been shared should say what happened rather than stop answering.
     */
    public function campaign(Campaign $campaign): View
    {
        abort_unless($campaign->is_published, 404);

        $campaign->load('beneficiary');

        return view('public.campaign', [
            'campaign' => $campaign,
            // An Eloquent collection, not a plain one: the block below
            // eager-loads the family it reads, and only Eloquent can.
            'monthly' => $this->monthlyFunding($campaign->newCollection([$campaign]))[$campaign->id] ?? null,
        ]);
    }

    /**
     * T-241 — the gift card itself, read at its own private address.
     *
     * Open to anybody who holds the link, because the recipient has no
     * account and was never asked for one. What the page carries is the two
     * names, the design, and the amount only where the donor said to show
     * it. Never the recipient's number, never a family, never a file.
     *
     * A card that has not been issued is a 404 rather than a message: the
     * transfer behind it is not verified yet, and a page that says «soon»
     * is a page that confirms the token is real.
     */
    public function giftCard(string $token): View
    {
        $gift = Gift::query()
            ->where('token', $token)
            ->where('status', 'issued')
            ->with(['card', 'campaign'])
            ->first();

        abort_unless($gift, 404);

        /*
         | T-251 — the QR the card prints points back at this very page, so
         | anybody holding the card can satisfy themselves it is real. Drawn
         | from our own QR service (T-119), which was written rather than
         | installed and never sends anything anywhere.
         |
         | The matrix goes to the page as well as the drawing, because the
         | download below redraws the code onto a canvas and cannot read it
         | back out of an image.
         */
        $qr = app(QrCode::class);
        $url = route('gift.card', ['token' => $gift->token]);

        return view('public.gift-card', [
            'gift' => $gift,
            'qrSvg' => $qr->svg($url, moduleSize: 4, quietZone: 2),
            'qrMatrix' => array_map(
                fn (array $row) => array_map(fn (bool $dark) => $dark ? 1 : 0, $row),
                $qr->matrix($url),
            ),
            'legalName' => trim((string) Setting::value(
                'association_legal_name',
                config('sanabel.setting_defaults.association_legal_name')
            )),
            'licenceNo' => trim((string) Setting::value(
                'association_licence_no',
                config('sanabel.setting_defaults.association_licence_no')
            )),
            'website' => trim((string) Setting::value(
                'association_website',
                config('sanabel.setting_defaults.association_website')
            )),
        ]);
    }

    /*
     | T-262 — «عرض للمتبرع ايصال عند التبرع», 8 November.
     |
     | Read by a token, like the gift card and for the same reason: a guest
     | donation has no account to open it from, and the address of one
     | receipt must say nothing about anybody else's.
     |
     | A reversal row has no page. It is the association's own correcting
     | entry (rule 5), not a donation anybody made, and a second receipt for
     | money that was handed over once would be a receipt that lies about
     | how much was given. The original says «معكوس» on its own page, which
     | is where a donor would look.
     |
     | What is never on it: a family, a file number, a wallet, or anything
     | else rule 2 keeps off a donor's screen. Whom the money reached is the
     | donor's own page behind their account, not a link that gets forwarded.
     */
    public function donationReceipt(string $token): View
    {
        $donation = Donation::query()
            ->where('receipt_token', $token)
            ->whereNull('reversal_of_id')
            ->with('donor')
            ->first();

        abort_unless($donation, 404);

        $qr = app(QrCode::class);
        $url = route('donation.receipt', ['token' => $donation->receipt_token]);

        /*
         | T-263 — a gift to a door is named by the door, not by «الملفات التي
         | اخترتها»: the donor chose «كفالة حلقة قرآن» and chose no file at
         | all. The door's own number goes with it, which is the number the
         | association reads the transfer against.
         |
         | A door names no family, so this adds nothing rule 2 keeps off the
         | page.
         */
        $door = $donation->allocations()
            ->whereNotNull('campaign_id')
            ->with('campaign')
            ->get()
            ->pluck('campaign')
            ->filter(fn (?Campaign $c) => $c?->isDoor())
            ->first();

        return view('public.donation-receipt', [
            'donation' => $donation,
            'door' => $door,
            'qrSvg' => $qr->svg($url, moduleSize: 4, quietZone: 2),
            'legalName' => trim((string) Setting::value(
                'association_legal_name',
                config('sanabel.setting_defaults.association_legal_name')
            )),
            'licenceNo' => trim((string) Setting::value(
                'association_licence_no',
                config('sanabel.setting_defaults.association_licence_no')
            )),
        ]);
    }

    /**
     * T-83 — the family's monthly funding to show beside its own campaign,
     * keyed by campaign, and empty when the association has closed the switch.
     *
     * The association asked for the choice on 13 October: a campaign answers a
     * one-off cost, and whether the monthly need goes on the same page beside
     * it is theirs to decide. Masked like every other donor view — rule 2 has
     * no exception for a campaign page.
     *
     * The month is the current one, named explicitly. A campaign is not a
     * month's pledge, so the block says how this month stands and leaves the
     * turn of the month to the family's own page, which is a click away. It is
     * also what keeps the page to one batch of sums instead of walking the
     * open months once per campaign on it.
     *
     * @param  Collection<int,Campaign>  $campaigns
     * @return array<int,array<string,mixed>>
     */
    private function monthlyFunding($campaigns): array
    {
        $on = (bool) Setting::value(
            'show_monthly_funding_on_campaign',
            config('sanabel.setting_defaults.show_monthly_funding_on_campaign')
        );

        if (! $on) {
            return [];
        }

        $withFamily = $campaigns->filter(fn (Campaign $campaign) => $campaign->beneficiary !== null);

        if ($withFamily->isEmpty()) {
            return [];
        }

        // Everything the card reads, loaded for the whole page at once.
        $families = $withFamily->map(fn (Campaign $campaign) => $campaign->beneficiary);
        $families->load(['assessments.overrides', 'region', 'members', 'housing', 'healthRecords', 'basketItems.basket']);

        $month = $this->coverage->currentMonth();
        $funded = $this->coverage->fundedForMonthForMany($families, $month);

        return $withFamily
            ->mapWithKeys(fn (Campaign $campaign) => [
                $campaign->getKey() => (new MaskedCaseResource(
                    $campaign->beneficiary,
                    $funded[$campaign->beneficiary->getKey()] ?? 0,
                    $month,
                ))->resolve(),
            ])
            ->all();
    }
}
