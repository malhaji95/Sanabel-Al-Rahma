@extends('layouts.app')

{{--
    T-89 — the home page the association approved on 13 October.

    Nothing on it is written here: the slides are banners, the offers, the ways
    to give and the promises are content cards, the two appeals are published
    campaigns, the figures are counted from the files themselves, and the two
    closing cards are CMS pages. The template says where things go and what
    they look like; the association says what they are.
--}}

@section('content')
    {{-- ---------- the hero ---------------------------------------------- --}}
    @if ($banners->isNotEmpty())
        <section
            class="card relative overflow-hidden !p-0"
            {{-- T-99 — «بنرات متحركة»: it advances by itself every six seconds,
                 and stops the moment a reader points at it or tabs into it, so
                 the slide does not move out from under somebody reading it.
                 A browser asked to reduce motion is left alone. --}}
            x-data="{
                slide: 0,
                count: {{ $banners->count() }},
                timer: null,
                start() {
                    if (this.count < 2 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return
                    this.timer = setInterval(() => { this.slide = (this.slide + 1) % this.count }, 6000)
                },
                stop() { clearInterval(this.timer) },
            }"
            x-init="start()"
            x-on:mouseenter="stop()"
            x-on:mouseleave="start()"
            x-on:focusin="stop()"
            aria-roledescription="carousel"
            aria-label="{{ __('sanabel.public.hero_carousel') }}"
        >
            @foreach ($banners as $index => $banner)
                <div
                    x-show="slide === {{ $index }}"
                    x-transition.opacity.duration.400ms
                    @class(['relative', 'hidden' => $index > 0])
                    role="group"
                    aria-roledescription="slide"
                >
                    @if ($banner->imageUrl())
                        <img
                            src="{{ $banner->imageUrl() }}"
                            alt=""
                            {{-- T-107 — «البنارات يكون أحجامهم صغيرة … مشان
                                 لما نفتح الموقع على الجوال يكون كل شي واضح»،
                                 16 October. A phone screen is about 650px
                                 tall, and a 260px banner took forty per cent
                                 of it before a reader saw anything else. The
                                 desktop heights are the ones the association's
                                 own asset pack asks for, 300 to 450. --}}
                            class="h-[180px] w-full object-cover sm:h-[300px] lg:h-[380px]"
                            @if ($index === 0) fetchpriority="high" @else loading="lazy" @endif
                        >
                    @else
                        <div class="h-[180px] w-full sm:h-[300px] lg:h-[380px]" style="background-color: var(--accent);"></div>
                    @endif

                    {{-- The text sits on the picture, so it carries its own
                         scrim: a photograph cannot be relied on for contrast. --}}
                    <div
                        class="absolute inset-0 flex flex-col items-center justify-center gap-2 p-4 text-center sm:gap-4 sm:p-6"
                        style="background: linear-gradient(to left, rgb(0 0 0 / 0.55), rgb(0 0 0 / 0.25));"
                    >
                        <h1 class="text-xl font-bold text-white drop-shadow sm:text-3xl lg:text-4xl">{{ $banner->title_ar }}</h1>

                        {{-- Hidden on a phone rather than wrapped to four
                             lines over the picture: the headline and the
                             button are what the slide is for. --}}
                        @if ($banner->body_ar)
                            <p class="hidden max-w-2xl text-white/90 drop-shadow sm:block sm:text-lg">{{ $banner->body_ar }}</p>
                        @endif

                        <a href="{{ $banner->destination() ?: route('cases.browse') }}"
                           class="btn-primary mt-2 inline-flex items-center gap-2 no-underline">
                            <x-brand.icon name="heart" class="h-4 w-4" />
                            {{ __('sanabel.public.donate_now') }}
                        </a>
                    </div>
                </div>
            @endforeach

            @if ($banners->count() > 1)
                <button type="button"
                        x-on:click="slide = (slide + 1) % count"
                        class="absolute end-3 top-1/2 -translate-y-1/2 rounded-full bg-white/80 p-2 text-slate-800 shadow"
                        aria-label="{{ __('sanabel.public.previous_slide') }}">
                    <x-brand.icon name="chevron-right" class="h-5 w-5" />
                </button>

                <button type="button"
                        x-on:click="slide = (slide - 1 + count) % count"
                        class="absolute start-3 top-1/2 -translate-y-1/2 rounded-full bg-white/80 p-2 text-slate-800 shadow"
                        aria-label="{{ __('sanabel.public.next_slide') }}">
                    <x-brand.icon name="chevron-left" class="h-5 w-5" />
                </button>

                <div class="absolute inset-x-0 bottom-4 flex justify-center gap-2">
                    @foreach ($banners as $index => $banner)
                        <button type="button"
                                x-on:click="slide = {{ $index }}"
                                class="h-2.5 w-2.5 rounded-full transition"
                                x-bind:class="slide === {{ $index }} ? 'bg-white' : 'bg-white/50'"
                                aria-label="{{ __('sanabel.public.go_to_slide', ['number' => $index + 1]) }}"></button>
                    @endforeach
                </div>
            @endif
        </section>
    @endif

    {{-- ---------- the two offers ----------------------------------------- --}}
    @if ($features->isNotEmpty())
        <div class="mt-6 grid gap-4 sm:grid-cols-2">
            @foreach ($features as $feature)
                <article class="card flex items-center gap-4 overflow-hidden !p-0">
                    @if ($feature->imageUrl())
                        <img src="{{ $feature->imageUrl() }}" alt=""
                             class="h-36 w-36 shrink-0 object-cover sm:h-40 sm:w-44" loading="lazy">
                    @elseif ($feature->icon)
                        <div class="flex h-36 w-36 shrink-0 items-center justify-center sm:h-40 sm:w-44"
                             style="background-color: var(--page); color: var(--accent);">
                            <x-brand.icon :name="$feature->icon" class="h-12 w-12" />
                        </div>
                    @endif

                    <div class="min-w-0 flex-1 py-4 pe-4">
                        <h2 class="text-xl font-bold" style="color: var(--accent);">{{ $feature->title_ar }}</h2>

                        @if ($feature->body_ar)
                            <p class="mt-1 text-sm leading-relaxed" style="color: var(--text-muted);">
                                {{ $feature->body_ar }}
                            </p>
                        @endif

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            @if ($feature->link)
                                <a href="{{ $feature->link }}" class="btn-primary inline-flex items-center gap-2 no-underline">
                                    <x-brand.icon name="heart" class="h-4 w-4" />
                                    {{ __('sanabel.public.donate_now') }}
                                </a>
                            @endif

                            {{-- The live count the design asks for, on the card
                                 that offers the list it counts. --}}
                            @if ($loop->first)
                                <span class="badge inline-flex items-center gap-1.5"
                                      style="background-color: var(--page); color: var(--text-muted);">
                                    <x-brand.icon name="card" class="h-3.5 w-3.5" />
                                    <span class="tabular font-semibold" style="color: var(--text);">{{ number_format($stats['open']) }}</span>
                                    {{ __('sanabel.public.open_cases_now') }}
                                </span>
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    {{-- ---------- the figures --------------------------------------------
         T-99 — each counter has its own switch on the settings screen, so the
         association turns one off without losing the rest. The money ones read
         verified transfers only: a pending one is not a donation yet, and a
         figure on the front page that counted it would be a claim the books
         cannot support.
    --------------------------------------------------------------------- --}}
    @php
        $on = fn (string $key) => (bool) \App\Models\Setting::value($key, config('sanabel.setting_defaults.'.$key));

        $figures = collect([
            ['stat_families', $stats['families'], __('sanabel.public.stat_families'), 'family', 'var(--accent)', false],
            ['stat_bands', $stats['bands']['critical'], __('sanabel.masked.priority.critical'), 'heart', '#b91c1c', false],
            ['stat_bands', $stats['bands']['middle'], __('sanabel.masked.priority.middle'), 'hand-heart', 'var(--brand-gold)', false],
            ['stat_bands', $stats['bands']['low'], __('sanabel.masked.priority.low'), 'info', 'var(--text-muted)', false],
            ['stat_covered', $stats['bands']['complete'], __('sanabel.public.stat_covered'), 'check-circle', 'var(--accent)', false],
            ['stat_donated', $stats['donated'], __('sanabel.public.stat_donated'), 'card', 'var(--brand-gold)', true],
            ['stat_donations', $stats['donations'], __('sanabel.public.stat_donations'), 'hand-heart', 'var(--accent)', false],
        ])->filter(fn (array $figure) => $on($figure[0]));
    @endphp

    @if ($figures->isNotEmpty())
        <section class="card mt-6 !p-0" aria-label="{{ __('sanabel.public.stats') }}">
            <div @class([
                'grid grid-cols-2',
                'sm:grid-cols-3' => $figures->count() === 3,
                'sm:grid-cols-4' => $figures->count() === 4,
                'sm:grid-cols-5' => $figures->count() >= 5,
            ])>
                @foreach ($figures as [$key, $value, $label, $icon, $colour, $isMoney])
                    <div class="border-b border-s p-4 text-center last:border-b-0 sm:border-b-0"
                         style="border-color: var(--border);">
                        <x-brand.icon :name="$icon" class="mx-auto mb-1.5 h-6 w-6" style="color: {{ $colour }};" />
                        <p class="tabular text-2xl font-bold" style="color: {{ $colour }};">
                            {{ number_format($value) }}
                            @if ($isMoney)
                                <span class="text-xs font-normal" style="color: var(--text-muted);">{{ config('sanabel.currency') }}</span>
                            @endif
                        </p>
                        <p class="mt-1 text-xs" style="color: var(--text-muted);">{{ $label }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ---------- today's appeals ---------------------------------------- --}}
    <section class="mt-10">
        {{-- T-103 — the heading alone. «عرض جميع الحالات» used to sit beside
             it, above the three cards it was meant to follow, and the
             association asked for it under them: a visitor asks for more only
             after reading what is there. --}}
        <h2 class="mb-4 inline-flex items-center gap-2 text-xl">
            <x-brand.icon name="heart" class="h-5 w-5" style="color: var(--accent);" />
            {{ __('sanabel.public.needs_you_today') }}
        </h2>

        {{-- The open campaigns first — they are written and published by the
             association, so they carry a title and a picture — then the masked
             families fill the row. A family's card never carries a photograph
             (rule 2), which is why the two look different on purpose. --}}
        @php($open = $campaigns->take(2))

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($open as $campaign)
                <x-campaign-card :campaign="$campaign" :monthly="$monthlyFunding[$campaign->id] ?? null" />
            @endforeach

            @foreach ($cases->take(max(0, 3 - $open->count())) as $case)
                <x-masked-case-card :case="$case">
                    <a href="{{ route('opportunity', $case['file_number']) }}"
                       class="btn-primary w-full justify-center gap-1 no-underline">
                        {{ __('sanabel.public.more_info') }}
                        <x-brand.icon name="chevron-left" class="h-4 w-4" />
                    </a>
                </x-masked-case-card>
            @endforeach
        </div>

        <div class="mt-5 text-center">
            <a href="{{ route('cases.browse') }}" class="btn-secondary inline-flex items-center gap-1 no-underline">
                {{ __('sanabel.public.all_cases') }}
                <x-brand.icon name="chevron-left" class="h-4 w-4" />
            </a>
        </div>
    </section>

    {{-- ---------- ways to give, first half -------------------------------- --}}
    <x-home.tiles :tiles="$tiles->take(4)" class="mt-6" />

    {{-- ---------- what sets the association apart -------------------------- --}}
    @if ($advantages->isNotEmpty())
        <section class="card mt-10 overflow-hidden !p-0">
            <div class="p-8"
                 style="background: linear-gradient(135deg, color-mix(in srgb, var(--accent) 10%, var(--card)), var(--page));">
                <h2 class="mb-6 text-center text-xl font-bold" style="color: var(--accent);">
                    {{ __('sanabel.public.what_sets_us_apart') }}
                </h2>

                <div class="grid gap-6 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ($advantages as $advantage)
                        <div class="text-center">
                            @if ($advantage->icon)
                                <x-brand.icon :name="$advantage->icon" class="mx-auto mb-3 h-9 w-9" style="color: var(--accent);" />
                            @endif
                            <p class="text-sm leading-relaxed">{{ $advantage->title_ar }}</p>
                            @if ($advantage->body_ar)
                                <p class="mt-1 text-xs" style="color: var(--text-muted);">{{ $advantage->body_ar }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ---------- ways to give, second half -------------------------------- --}}
    <x-home.tiles :tiles="$tiles->slice(4)" class="mt-6" />

    {{-- ---------- the association, and the news ---------------------------- --}}
    <section class="mt-10 grid gap-4 lg:grid-cols-3">
        @foreach ([$shariaBoard, $about] as $page)
            @if ($page)
                <article class="card">
                    <h2 class="inline-flex items-center gap-2 text-lg">
                        <x-brand.icon :name="$loop->first ? 'scales' : 'info'" class="h-5 w-5" style="color: var(--accent);" />
                        {{ $page->title_ar }}
                    </h2>

                    <p class="mt-3 line-clamp-4 text-sm leading-relaxed" style="color: var(--text-muted);">
                        {{ Str::limit(strip_tags($page->body_ar), 180) }}
                    </p>

                    <a href="{{ route('page', $page->slug) }}"
                       class="btn-secondary mt-4 inline-flex items-center gap-1 no-underline">
                        {{ __('sanabel.public.read_more') }}
                        <x-brand.icon name="chevron-left" class="h-4 w-4" />
                    </a>
                </article>
            @endif
        @endforeach

        <article class="card">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-lg">{{ __('sanabel.public.news') }}</h2>
                <a href="{{ route('news') }}" class="text-sm no-underline" style="color: var(--accent);">
                    {{ __('sanabel.public.more') }}
                </a>
            </div>

            <ul class="mt-3 space-y-3">
                @forelse ($posts as $post)
                    <li class="flex items-start gap-3">
                        @if ($post->image)
                            <img src="{{ Storage::disk('public')->url($post->image) }}" alt=""
                                 class="h-14 w-20 shrink-0 rounded-lg object-cover" loading="lazy">
                        @endif
                        <div class="min-w-0">
                            <a href="{{ route('post', $post->slug) }}" class="text-sm font-medium no-underline">
                                {{ $post->title_ar }}
                            </a>
                            <p class="tabular mt-1 text-xs" style="color: var(--text-muted);" dir="ltr">
                                {{ $post->published_at?->format('Y/m/d') }}
                            </p>
                        </div>
                    </li>
                @empty
                    <li class="text-sm" style="color: var(--text-muted);">{{ __('sanabel.public.no_news') }}</li>
                @endforelse
            </ul>
        </article>
    </section>

    {{-- ---------- our partners ------------------------------------------
         T-99 — at the foot of the page and nowhere else, as the association
         asked on 15 October. Rows like everything else: a logo, a name, and a
         link to the partner's own site if there is one. The band does not
         exist until they add the first one, so the page never carries an
         empty «شركاؤنا» heading.
    --------------------------------------------------------------------- --}}
    @if ($partners->isNotEmpty())
        <section class="mt-10" aria-label="{{ __('sanabel.public.partners') }}">
            <h2 class="mb-4 inline-flex items-center gap-2 text-xl">
                <x-brand.icon name="hand-heart" class="h-5 w-5" style="color: var(--accent);" />
                {{ __('sanabel.public.partners') }}
            </h2>

            <div class="card flex flex-wrap items-center justify-center gap-x-10 gap-y-6 py-6">
                @foreach ($partners as $partner)
                    @php($logo = $partner->imageUrl())

                    <a @if ($partner->link) href="{{ $partner->link }}" rel="noopener" target="_blank" @endif
                       class="inline-flex flex-col items-center gap-2 no-underline"
                       title="{{ $partner->title_ar }}">
                        @if ($logo)
                            {{-- Greyed until pointed at, so a row of logos does
                                 not shout louder than the families above it. --}}
                            <img src="{{ $logo }}" alt="{{ $partner->title_ar }}" loading="lazy"
                                 class="h-12 w-auto opacity-70 grayscale transition hover:opacity-100 hover:grayscale-0">
                        @else
                            <x-brand.icon name="shield-check" class="h-10 w-10" style="color: var(--text-muted);" />
                        @endif

                        <span class="text-xs" style="color: var(--text-muted);">{{ $partner->title_ar }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
@endsection
