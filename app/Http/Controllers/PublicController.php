<?php

namespace App\Http\Controllers;

use App\Http\Resources\MaskedCaseResource;
use App\Models\Banner;
use App\Models\Beneficiary;
use App\Models\Campaign;
use App\Models\Page;
use App\Models\Post;
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
            ->map(fn (array $row) => (new MaskedCaseResource($row['beneficiary'], $row['confirmed']))->resolve());

        return view('public.home', [
            'banners' => Banner::where('is_published', true)->orderBy('sort_order')->get(),
            'posts' => Post::where('is_published', true)->orderByDesc('published_at')->take(3)->get(),
            'campaigns' => Campaign::where('is_published', true)
                ->where('status', 'active')
                ->orderByDesc('id')->take(3)->get(),
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
        $closed = $case === null || $coverage->remainingNeed($case) <= 0;

        return view('public.case', [
            'card' => $case ? (new MaskedCaseResource($case))->resolve() : null,
            'closed' => $closed,
            // Something else to give to, so a finished link is not a dead end.
            'alternatives' => $closed
                ? app(RankingService::class)->fundingList('monthly')->take(3)
                    ->map(fn (array $row) => (new MaskedCaseResource($row['beneficiary'], $row['confirmed']))->resolve())
                    ->values()
                : collect(),
        ]);
    }

    public function campaigns(): View
    {
        return view('public.campaigns', [
            'campaigns' => Campaign::where('is_published', true)
                ->whereIn('status', ['active', 'funded', 'awaiting_execution'])
                ->orderByDesc('id')
                ->paginate(12),
        ]);
    }
}
