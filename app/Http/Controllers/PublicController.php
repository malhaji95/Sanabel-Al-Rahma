<?php

namespace App\Http\Controllers;

use App\Http\Resources\MaskedCaseResource;
use App\Models\Banner;
use App\Models\Beneficiary;
use App\Models\Campaign;
use App\Models\ContentCard;
use App\Models\Donation;
use App\Models\Page;
use App\Models\Post;
use App\Models\Region;
use App\Models\Setting;
use App\Services\CoverageService;
use App\Services\DonorCaseList;
use App\Services\RankingService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/** T-36 — the public site, driven entirely by CMS rows an admin edits. */
class PublicController extends Controller
{
    public function __construct(
        private readonly RankingService $ranking,
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
            'stats' => $this->stats(),
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

    /**
     * Counts only. Nothing here identifies a family.
     *
     * T-87 — it used to load every published family with its assessments to
     * count how many were covered, which is the homepage carrying the whole
     * table on every visit. It now asks for the needs alone and counts what is
     * still short: a family with no approved assessment has no need and is
     * covered, exactly as before, which is why the covered figure is the total
     * less the short rather than a filter over models.
     */
    private function stats(): array
    {
        $needs = $this->ranking->publishedNeeds();
        $funded = $this->coverage->fundedForMonthByNeed($needs);

        $families = Beneficiary::published()->count();

        // T-89 — the five figures the design asks for, over the needs alone.
        // A family with no approved assessment has no need and no band; it is
        // counted in the total and read as covered, as it always was.
        $bands = ['critical' => 0, 'middle' => 0, 'low' => 0, 'complete' => 0];

        foreach ($needs as $id => $need) {
            $bands[$this->coverage->bandOf($need, $funded[$id] ?? 0)]++;
        }

        $bands['complete'] += $families - count($needs);

        /*
         | T-99 — the two figures the association asked for on 15 October, and
         | both are money, so both are read from verified rows only: a transfer
         | the finance desk has matched to its notice. A pending one is not a
         | donation yet, and a figure on the front page that counts it would be
         | a claim the books cannot support.
         |
         | Rule 9 — integers in the smallest unit, formatted where they are
         | shown and never divided here.
         */
        $verified = Donation::where('status', 'verified');

        return [
            'families' => $families,
            'regions' => Region::where('is_active', true)->where('type', '!=', 'governorate')->count(),
            'covered' => $bands['complete'],
            'bands' => $bands,
            // What the «الدفع المباشر» card counts: families a donor could give
            // to right now.
            'open' => $families - $bands['complete'],
            'donated' => (int) (clone $verified)->sum('amount'),
            // «عداد التبرع المباشر»: how many transfers arrived and were
            // matched. A count rather than a sum, beside the sum, so the page
            // says both «how much» and «from how many hands».
            'donations' => (clone $verified)->count(),
        ];
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
