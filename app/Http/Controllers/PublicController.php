<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\Campaign;
use App\Models\Page;
use App\Models\Post;
use Illuminate\View\View;

/** T-36 — the public site, driven entirely by CMS rows an admin edits. */
class PublicController extends Controller
{
    public function home(): View
    {
        return view('public.home', [
            'banners' => Banner::where('is_published', true)->orderBy('sort_order')->get(),
            'posts' => Post::where('is_published', true)->orderByDesc('published_at')->take(3)->get(),
            'campaigns' => Campaign::where('is_published', true)
                ->where('status', 'active')
                ->orderByDesc('id')->take(3)->get(),
        ]);
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
