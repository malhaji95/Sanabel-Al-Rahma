<?php

namespace App\Http\Controllers;

use App\Http\Resources\MaskedCaseResource;
use App\Models\Banner;
use App\Models\Beneficiary;
use App\Models\Campaign;
use App\Models\Page;
use App\Models\Post;
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
            ->map(fn (array $row) => (new MaskedCaseResource($row['beneficiary']))->resolve());

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
        $published = Beneficiary::published()->get();

        return [
            'families' => $published->count(),
            'regions' => Region::where('is_active', true)->where('type', '!=', 'governorate')->count(),
            'covered' => $published->filter(fn (Beneficiary $c) => $this->coverage->coverageRatio($c) >= 1.0)->count(),
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
