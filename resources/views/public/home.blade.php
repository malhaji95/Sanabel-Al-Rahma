@extends('layouts.app')

@section('content')
    {{-- Hero. The full lockup gets room and clear space, as the identity guide requires. --}}
    <section class="card overflow-hidden !p-0">
        <div class="grid items-center gap-8 p-8 sm:p-12 lg:grid-cols-[1fr_auto]">
            <div>
                {{-- On a narrow screen the lockup leads, above the heading. --}}
                <x-brand.logo-plate variant="full" :height="150" class="mb-6 lg:hidden" />

                <h1 class="text-3xl leading-tight sm:text-4xl">{{ __('sanabel.public.hero_title') }}</h1>

                <div class="rule-gold my-5 max-w-xs"></div>

                <p class="max-w-xl text-base leading-relaxed" style="color: var(--text-muted);">
                    {{ __('sanabel.public.hero_body') }}
                </p>

                <div class="mt-7 flex flex-wrap gap-3">
                    <a href="{{ route('cases.browse') }}" class="btn-primary no-underline">
                        {{ __('sanabel.public.browse_cases') }}
                    </a>
                    <a href="{{ route('campaigns.public') }}" class="btn-secondary no-underline">
                        {{ __('sanabel.public.campaigns') }}
                    </a>
                </div>
            </div>

            <x-brand.logo-plate variant="full" :height="200" class="mx-auto hidden lg:inline-flex" />
        </div>

        <div class="grid grid-cols-3 border-t" style="border-color: var(--border);">
            @foreach ([
                [$stats['families'], __('sanabel.public.stat_families')],
                [$stats['regions'], __('sanabel.public.stat_regions')],
                [$stats['covered'], __('sanabel.public.stat_covered')],
            ] as [$value, $label])
                <div class="border-s p-5 text-center first:border-s-0" style="border-color: var(--border);">
                    <p class="tabular text-2xl font-bold" style="color: var(--accent);">{{ number_format($value) }}</p>
                    <p class="mt-1 text-xs" style="color: var(--text-muted);">{{ $label }}</p>
                </div>
            @endforeach
        </div>
    </section>

    @foreach ($banners as $banner)
        <section class="alert-warning mt-6" role="note">
            <div>
                <p class="font-semibold">{{ $banner->title_ar }}</p>
                @if ($banner->body_ar)
                    <p class="mt-1">{{ $banner->body_ar }}</p>
                @endif
                @if ($banner->link)
                    <a href="{{ $banner->link }}" class="mt-2 inline-block font-medium">{{ __('sanabel.public.more') }}</a>
                @endif
            </div>
        </section>
    @endforeach

    {{-- How a donation actually reaches a family. --}}
    <section class="mt-12">
        <h2 class="text-xl">{{ __('sanabel.public.how_title') }}</h2>

        <ol class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([1, 2, 3, 4] as $step)
                <li class="card">
                    <span
                        class="tabular inline-flex h-9 w-9 items-center justify-center rounded-full text-sm font-bold"
                        style="background-color: var(--accent); color: var(--accent-contrast);"
                        aria-hidden="true"
                    >{{ $step }}</span>
                    <h3 class="mt-3 text-base">{{ __("sanabel.public.how_{$step}_title") }}</h3>
                    <p class="mt-1.5 text-sm leading-relaxed" style="color: var(--text-muted);">
                        {{ __("sanabel.public.how_{$step}_body") }}
                    </p>
                </li>
            @endforeach
        </ol>

        <p class="mt-4 text-sm" style="color: var(--text-muted);">
            <svg class="me-1 inline h-4 w-4 align-[-2px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path d="M12 3 4 6v6c0 4.5 3.2 8.3 8 9 4.8-.7 8-4.5 8-9V6l-8-3Z" stroke-linejoin="round"/>
            </svg>
            {{ __('sanabel.public.privacy_note') }}
        </p>
    </section>

    @if ($cases->isNotEmpty())
        <section class="mt-12">
            <div class="flex items-end justify-between gap-4">
                <h2 class="text-xl">{{ __('sanabel.public.urgent_first') }}</h2>
                <a href="{{ route('cases.browse') }}" class="text-sm font-medium">{{ __('sanabel.public.view_all') }}</a>
            </div>

            <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($cases as $case)
                    <x-masked-case-card :case="$case">
                        <a href="{{ route('cases.browse') }}" class="btn-secondary w-full no-underline">
                            {{ __('sanabel.public.support_this_family') }}
                        </a>
                    </x-masked-case-card>
                @endforeach
            </div>
        </section>
    @endif

    @if ($campaigns->isNotEmpty())
        <section class="mt-12">
            <div class="flex items-end justify-between gap-4">
                <h2 class="text-xl">{{ __('sanabel.public.campaigns') }}</h2>
                <a href="{{ route('campaigns.public') }}" class="text-sm font-medium">{{ __('sanabel.public.view_all') }}</a>
            </div>

            <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($campaigns as $campaign)
                    <x-campaign-card :campaign="$campaign" />
                @endforeach
            </div>
        </section>
    @endif

    @if ($posts->isNotEmpty())
        <section class="mt-12">
            <div class="flex items-end justify-between gap-4">
                <h2 class="text-xl">{{ __('sanabel.public.news') }}</h2>
                <a href="{{ route('news') }}" class="text-sm font-medium">{{ __('sanabel.public.view_all') }}</a>
            </div>

            <div class="mt-5 grid gap-4 sm:grid-cols-3">
                @foreach ($posts as $post)
                    <a href="{{ route('post', $post->slug) }}" class="card-interactive block no-underline">
                        <h3 class="text-base">{{ $post->title_ar }}</h3>
                        <p class="mt-2 line-clamp-3 text-sm leading-relaxed" style="color: var(--text-muted);">
                            {{ $post->body_ar }}
                        </p>
                        <span class="mt-3 inline-block text-sm font-medium" style="color: var(--accent);">
                            {{ __('sanabel.public.read_more') }}
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
@endsection
