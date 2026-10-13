<?php

namespace App\Http\Controllers;

use App\Http\Resources\MaskedCaseResource;
use App\Models\Banner;
use App\Models\Beneficiary;
use App\Models\Campaign;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use Illuminate\Http\Request;
use App\Models\Region;
use App\Services\CoverageService;
use App\Services\RankingService;
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
        // The three most urgent published cases, masked like every donor view.
        $urgent = $this->ranking->fundingList('monthly')
            ->take(3)
            ->map(fn (array $row) => (new MaskedCaseResource(
                $row['beneficiary'],
                $row['confirmed'],
                $row['month'],
            ))->resolve());

        $campaigns = Campaign::where('is_published', true)
            ->where('status', 'active')
            ->with('beneficiary')
            ->orderByDesc('id')->take(3)->get();

        return view('public.home', [
            'banners' => Banner::where('is_published', true)->orderBy('sort_order')->get(),
            'posts' => Post::where('is_published', true)->orderByDesc('published_at')->take(3)->get(),
            'campaigns' => $campaigns,
            'monthlyFunding' => $this->monthlyFunding($campaigns),
            'cases' => $urgent,
            'stats' => $this->stats(),
        ]);
    }

    /** Counts only. Nothing here identifies a family. */
    private function stats(): array
    {
        // Assessments eager-loaded and the confirmed sums fetched in one query:
        // asking per family made the homepage cost a query per published case.
        $published = Beneficiary::published()->with('assessments')->get();
        $confirmed = $this->coverage->fundedForMonthForMany($published);

        $isCovered = function (Beneficiary $case) use ($confirmed): bool {
            $need = $this->coverage->needAmount($case);

            // No need recorded is full coverage, as in CoverageService::coverageRatio().
            return $need <= 0 || ($confirmed[$case->getKey()] ?? 0) >= $need;
        };

        return [
            'families' => $published->count(),
            'regions' => Region::where('is_active', true)->where('type', '!=', 'governorate')->count(),
            'covered' => $published->filter($isCovered)->count(),
        ];
    }

    public function page(string $slug): View
    {
        return view('public.page', [
            'page' => Page::where('slug', $slug)->where('is_published', true)->firstOrFail(),
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
            ->with(['region', 'members', 'housing', 'healthRecords', 'association'])
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
            'alternatives' => $closed
                ? app(RankingService::class)->fundingList('monthly')->take(3)
                    ->map(fn (array $row) => (new MaskedCaseResource(
                        $row['beneficiary'],
                        $row['confirmed'],
                        $row['month'],
                    ))->resolve())
                    ->values()
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
     * @param  \Illuminate\Support\Collection<int,Campaign>  $campaigns
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
