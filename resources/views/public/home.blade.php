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
    {{-- T-294 — the page's one heading. It is not drawn: the slides used to
         carry it, and there are five of them, so the page claimed five
         headings and a reader moving by heading was told the same thing five
         times. The slides are promotions, the association's name is what the
         page is. --}}
    <h1 class="sr-only">{{ __('sanabel.app_name') }}</h1>

    {{-- ---------- the hero ---------------------------------------------- --}}
    @if ($banners->isNotEmpty())
        {{-- T-327 — their approved page runs the hero the whole width of the
             window and lets the artwork keep its own 8:3, rather than setting
             it in a rounded card inside the column like every other band.
             The logical margins pull it out of the column in both directions,
             so the same rule serves the Arabic page and a left-to-right one. --}}
        <section
            class="relative overflow-hidden"
            style="margin-inline: calc(50% - 50vw); width: 100vw;"
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
            {{-- T-311 — «ليش عند تبديل الشرائح تظهر بهاد الشكل كأنه في خلل».
                 Each slide used to sit in the page's flow, and the crossfade
                 keeps the leaving slide displayed for its 400ms. So for those
                 400ms two slides were in the flow at once and the hero grew to
                 twice its height, one picture stacked under the other.

                 The slides now lie on top of one another inside one track of
                 fixed height. The heights T-107 settled are the track's, so
                 the hero is that tall whichever slide is showing, and whether
                 one is leaving or none at all. --}}
            {{-- T-311 kept the track a fixed height so two slides never stack.
                 T-327 keeps the track and changes the measure: the artwork is
                 1920×720, so the track is 8:3 and the picture is never cropped
                 at any width. --}}
            <div class="relative aspect-[8/3] w-full">
            @foreach ($banners as $index => $banner)
                {{-- T-295 — «الشريط بيختفي بعد ما بدّل الشريحة».
                     The slides after the first were held back with Tailwind's
                     `hidden`, so the page would not flash all six before
                     Alpine booted. But `x-show` only ever writes and clears
                     the element's own `display`; it cannot take a class off.
                     So the moment the carousel advanced, slide 0 was given
                     `display:none` and slide 1 had its inline style cleared
                     onto a class that still said `display:none` — and the
                     hero collapsed from 382px to 2 and stayed collapsed
                     until the count wrapped round to nought.

                     The guard it was there for is kept, written the one way
                     `x-show` can undo: inline. With no JavaScript at all the
                     first slide still shows alone, exactly as before. --}}
                <div
                    x-show="slide === {{ $index }}"
                    x-transition.opacity.duration.400ms
                    class="absolute inset-0"
                    @style(['display:none' => $index > 0])
                    role="group"
                    aria-roledescription="slide"
                >
                    @if ($banner->imageUrl())
                        <img
                            src="{{ $banner->imageUrl() }}"
                            {{-- T-310 — a picture that carries the words is
                                 described by them; a photograph behind a
                                 heading is decoration and says nothing. --}}
                            alt="{{ $banner->text_in_image ? $banner->title_ar : '' }}"
                            {{-- T-107 — «البنارات يكون أحجامهم صغيرة … مشان
                                 لما نفتح الموقع على الجوال يكون كل شي واضح»،
                                 16 October. A phone screen is about 650px
                                 tall, and a 260px banner took forty per cent
                                 of it before a reader saw anything else. The
                                 desktop heights are the ones the association's
                                 own asset pack asks for, 300 to 450. --}}
                            class="h-full w-full object-cover"
                            @if ($index === 0) fetchpriority="high" @else loading="lazy" @endif
                        >
                    @else
                        <div class="h-full w-full" style="background-color: var(--accent);"></div>
                    @endif

                    {{-- T-310 — the five wide messages the association sent
                         on 23 November are finished compositions: headline,
                         sentence and button are drawn into the artwork. So the
                         slide is the picture and nothing else, and the whole
                         of it goes where its own painted button promises. --}}
                    @if ($banner->text_in_image)
                        @if ($banner->destination())
                            <a href="{{ $banner->destination() }}"
                               class="absolute inset-0"
                               aria-label="{{ $banner->title_ar }}"></a>
                        @endif
                    @else
                    {{-- The text sits on the picture, so it carries its own
                         scrim: a photograph cannot be relied on for contrast. --}}
                    <div
                        class="absolute inset-0 flex flex-col items-center justify-center gap-2 p-4 text-center sm:gap-4 sm:p-6"
                        style="background: linear-gradient(to left, rgb(0 0 0 / 0.55), rgb(0 0 0 / 0.25));"
                    >
                        <h2 class="text-xl font-bold text-white drop-shadow sm:text-3xl lg:text-4xl">{{ $banner->title_ar }}</h2>

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
                    @endif
                </div>
            @endforeach
            </div>

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

    {{-- ---------- the two promotions -------------------------------------
         T-327 — «اشتغل على نفس الشكل تماما», 26 November.

         These were two small cards: a 144px thumbnail at the start, a
         heading, a line and a button beside it. Their approved page gives
         each one a square picture the width of half the page, and nothing
         else — because the artwork already carries its own heading, its own
         sentence and its own button drawn into it (T-310's rule, on the two
         pictures it was written for).

         So the card is the picture, and the only thing added is the link
         under it. The words live in `alt`, where a reader who cannot see the
         picture still gets them.
    --------------------------------------------------------------------- --}}
    @if ($features->isNotEmpty())
        <div class="mt-6 grid gap-4 sm:grid-cols-2">
            @foreach ($features as $feature)
                @php($art = $feature->imageUrl())

                <article class="card overflow-hidden !p-0">
                    @if ($art)
                        <a href="{{ $feature->link ?: route('donate.general') }}" class="block no-underline">
                            <img src="{{ $art }}"
                                 alt="{{ trim($feature->title_ar.' — '.$feature->body_ar, ' —') }}"
                                 class="aspect-square w-full object-cover" loading="lazy">
                        </a>
                    @else
                        {{-- No artwork yet: the words it would have carried,
                             drawn rather than left as an empty frame. --}}
                        <div class="flex aspect-square w-full flex-col items-center justify-center gap-3 p-6 text-center"
                             style="background: linear-gradient(135deg, color-mix(in srgb, var(--accent) 24%, var(--card)), var(--page));">
                            @if ($feature->icon)
                                <x-brand.icon :name="$feature->icon" class="h-12 w-12" style="color: var(--accent);" />
                            @endif

                            <h2 class="text-xl font-bold" style="color: var(--accent);">{{ $feature->title_ar }}</h2>

                            @if ($feature->body_ar)
                                <p class="text-sm leading-relaxed" style="color: var(--text-muted);">{{ $feature->body_ar }}</p>
                            @endif

                            @if ($feature->link)
                                <a href="{{ $feature->link }}" class="btn-primary inline-flex items-center gap-2 no-underline">
                                    <x-brand.icon name="heart" class="h-4 w-4" />
                                    {{ __('sanabel.public.donate_now') }}
                                </a>
                            @endif
                        </div>
                    @endif
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

         T-183 — and it moves while the page is open. The strip is a Livewire
         component now; the switches, the figures and the words are the same.
    --------------------------------------------------------------------- --}}
    {{-- T-327 — their approved page gives the figures a heading, a line
         under it and a band of their own, rather than a strip with no name
         on it between two pictures. --}}
    <section id="numbers" class="mt-10 scroll-mt-24">
        <h2 class="text-xl">{{ __('sanabel.public.numbers_heading') }}</h2>
        <p class="mb-4 mt-1 text-sm" style="color: var(--text-muted);">
            {{ __('sanabel.public.numbers_lead') }}
        </p>

        <livewire:platform-counters />
    </section>

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

        {{--
            T-109 — the families, and only the families.

            The campaigns used to take the first two places in this row, on
            the reasoning that they are written and published so they carry a
            title and a picture while a family's card cannot (rule 2). The
            association corrected that on 17 October: «هذه ليست حالات فقراء،
            هذه الحملات. في البداية المفروض يكون حالات الفقراء، والحملات تكون
            تحت». A roof repair is a project; the heading above this row
            promises households.
        --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($cases->take(3) as $case)
                <x-masked-case-card :case="$case" illustration>
                    {{-- T-207 — «هون ما في يحط المبلغ ويحط إضافة بالسلة…
                         هون احنا نحتاج متل هيك, إنه يقدر يحط المبلغ»,
                         30 October.

                         The same box the list at /cases carries. A donor who
                         meets a family on the front page had to open the
                         file, then the list, before they could give; and the
                         «تبرع الآن» tile beside these cards goes straight to
                         the payment page, which is a different thing and not
                         a replacement for it. --}}
                    @if (auth()->check() && ! auth()->user()->isFamily())
                        <livewire:add-to-basket
                            :file-number="$case['file_number']"
                            :remaining="$case['remaining_amount']"
                            :target-remaining="$case['target_remaining'] ?? 0"
                            :coverage-month="$case['coverage_month'] ?? null"
                            :key="'home-basket-' . $case['file_number'] . '-' . ($case['coverage_month'] ?? '')"
                        />
                    @endif

                    <a href="{{ route('opportunity', $case['file_number']) }}"
                       class="btn-secondary mt-2 w-full justify-center gap-1 no-underline">
                        {{ __('sanabel.public.more_info') }}
                        <x-brand.icon name="chevron-left" class="h-4 w-4" />
                    </a>
                </x-masked-case-card>
            @empty
                {{--
                    T-265 — the heading promises households, so when there are
                    none it says so rather than opening on an empty strip of
                    page. The same answer «الأخبار» gives three sections down,
                    and the one every list on this site gives.

                    It is the state the front page is in on the morning of a
                    launch, before the first file is published, and on any day
                    the association has nothing open for a donor to take.
                --}}
                <p class="text-sm sm:col-span-2 lg:col-span-3" style="color: var(--text-muted);">
                    {{ __('sanabel.public.no_open_cases') }}
                </p>
            @endforelse
        </div>

        <div class="mt-5 flex flex-wrap items-center justify-center gap-3">
            <a href="{{ route('cases.browse') }}" class="btn-secondary inline-flex items-center gap-1 no-underline">
                {{ __('sanabel.public.all_cases') }}
                <x-brand.icon name="chevron-left" class="h-4 w-4" />
            </a>

            {{-- T-228 — the sponsorships have a page of their own, so there
                 is somewhere to send the donor who came for one kind of
                 family. Drawn only when there are doors to walk through. --}}
            @if (filled($sponsorshipDoors ?? []))
                <a href="{{ route('sponsorships.browse') }}" class="btn-secondary inline-flex items-center gap-1 no-underline">
                    {{ __('sanabel.public.all_sponsorships') }}
                    <x-brand.icon name="chevron-left" class="h-4 w-4" />
                </a>
            @endif
        </div>
    </section>

    {{-- ---------- the campaigns, under the families ----------------------- --}}
    @if ($campaigns->isNotEmpty())
        <section class="mt-10">
            <h2 class="mb-4 inline-flex items-center gap-2 text-xl">
                <x-brand.icon name="hand-heart" class="h-5 w-5" style="color: var(--brand-gold);" />
                {{ __('sanabel.public.open_campaigns') }}
            </h2>

            <p class="-mt-2 mb-4 text-sm" style="color: var(--text-muted);">
                {{ __('sanabel.public.campaigns_lead') }}
            </p>

            {{-- T-327 — four to a row, which is the row their approved page
                 draws. How many are actually there is the association's: the
                 band fills with whatever is published and active. --}}
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($campaigns->take(4) as $campaign)
                    <x-campaign-card :campaign="$campaign" :monthly="$monthlyFunding[$campaign->id] ?? null" />
                @endforeach
            </div>

            <div class="mt-5 text-center">
                <a href="{{ route('campaigns.public') }}" class="btn-secondary inline-flex items-center gap-1 no-underline">
                    {{ __('sanabel.public.all_campaigns') }}
                    <x-brand.icon name="chevron-left" class="h-4 w-4" />
                </a>
            </div>
        </section>
    @endif

    {{-- ---------- what sets the association apart ------------------------
         T-327 — «اشتغل على نفس الشكل تماما», 26 November.

         Six promises used to be drawn here as six icons over six lines of
         text. Their approved page puts one picture in their place — the
         association's own artwork, with the heading and the six cards set
         inside it — so that is what goes here.

         A picture that carries words is unreadable to a screen reader and
         invisible to a search engine, so the six headings follow it in text
         that is there for both and drawn for neither. The rows are still the
         association's, which is what keeps that list true when they edit one.
    --------------------------------------------------------------------- --}}
    <section id="features" class="mt-10 scroll-mt-24">
        <img src="{{ asset('images/home/features-six.webp') }}"
             alt="{{ __('sanabel.public.principles_six') }}"
             class="mx-auto w-full max-w-3xl rounded-2xl" loading="lazy">

        @if ($advantages->isNotEmpty())
            <ul class="sr-only">
                @foreach ($advantages as $advantage)
                    <li>{{ $advantage->title_ar }}@if ($advantage->body_ar) — {{ $advantage->body_ar }}@endif</li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- ---------- the eight ways to give, in one band ---------------------
         T-327 — they were four, then the promises, then four more. Their page
         carries the eight together under one heading, and a reader choosing
         between doors should see all of them at once.
    --------------------------------------------------------------------- --}}
    @if ($tiles->isNotEmpty())
        <section id="zakat" class="mt-10 scroll-mt-24">
            <h2 class="text-xl">{{ __('sanabel.public.giving_doors') }}</h2>
            <p class="mb-4 mt-1 text-sm" style="color: var(--text-muted);">
                {{ __('sanabel.public.giving_doors_lead') }}
            </p>

            <x-home.tiles :tiles="$tiles" />
        </section>
    @endif

    {{-- ---------- the health path ------------------------------------------
         T-150 — «هذا البنر الرئيسي للمسار الصحي … ممكن يكون فوق من نحن على عرض
         الشاشة», so: full width, directly above the association's own cards.

         «ساهم في العلاج» opens the donation page. Money given there reaches
         the association's fund like every other transfer, and the association
         spends it on the path — the domain has two kinds of donation and
         admits no third (docs/03-rules.md).

         «ساهم كمزود خدمة» has no page of its own yet: a provider is entered
         from the panel, and a public sign-up is part of the health path's
         second and third stages. Until it is built the button opens «تواصل
         معنا», so nothing on the banner leads nowhere.

         T-327 — a heading, a line and the three buttons their page sets under
         the picture.
    --------------------------------------------------------------------- --}}
    <section id="health" class="mt-10 scroll-mt-24">
        <h2 class="text-xl">{{ __('sanabel.public.health_path_heading') }}</h2>
        <p class="mb-4 mt-1 text-sm" style="color: var(--text-muted);">
            {{ __('sanabel.public.health_path_lead') }}
        </p>

        <x-home.health-path-banner
            :treatment="route('donate.general')"
            :provider="route('page', 'contact')"
        />

        <div class="mt-4 flex flex-wrap items-center justify-center gap-3">
            <a href="{{ route('donate.general') }}" class="btn-primary no-underline">
                {{ __('sanabel.public.health_support') }}
            </a>
            <a href="{{ route('page', 'contact') }}" class="btn-secondary no-underline">
                {{ __('sanabel.public.health_provider_signup') }}
            </a>
            <a href="{{ route('page', 'how-we-work') }}" class="btn-secondary no-underline">
                {{ __('sanabel.public.path_learn_more') }}
            </a>
        </div>
    </section>

    {{-- ---------- the sufficiency path -------------------------------------
         T-327 — a band of its own, which it never had on this page. Its
         banner was one slide of the carousel among eleven, and their page
         gives it the same standing as the health path: heading, picture, and
         the two buttons under it.
    --------------------------------------------------------------------- --}}
    <section id="kifaya" class="mt-10 scroll-mt-24">
        <h2 class="text-xl">{{ __('sanabel.public.kifaya_heading') }}</h2>

        <a href="{{ route('page', 'sufficiency-path') }}" class="mt-4 block no-underline">
            <img src="{{ asset('images/banners/sufficiency-path.webp') }}"
                 alt="{{ __('sanabel.public.kifaya_image_alt') }}"
                 class="w-full rounded-2xl" loading="lazy">
        </a>

        <div class="mt-4 flex flex-wrap items-center justify-center gap-3">
            <a href="{{ route('donate.general') }}" class="btn-primary no-underline">
                {{ __('sanabel.public.kifaya_support') }}
            </a>
            <a href="{{ route('page', 'sufficiency-path') }}" class="btn-secondary no-underline">
                {{ __('sanabel.public.path_learn_more') }}
            </a>
        </div>
    </section>

    {{-- ---------- know us --------------------------------------------------
         T-327 — two cards became six doors, which is what their page draws.
         Every one of them is a page the association already writes; the line
         under each heading is a row it edits.
    --------------------------------------------------------------------- --}}
    @if ($aboutTiles->isNotEmpty())
        <section id="about" class="mt-10 scroll-mt-24">
            <h2 class="text-xl">{{ __('sanabel.public.know_us') }}</h2>
            <p class="mb-4 mt-1 text-sm" style="color: var(--text-muted);">
                {{ __('sanabel.public.know_us_lead') }}
            </p>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($aboutTiles as $tile)
                    <a href="{{ $tile->link }}" class="card-interactive block text-center no-underline">
                        @if ($tile->icon)
                            <x-brand.icon :name="$tile->icon" class="mx-auto mb-2 h-7 w-7" style="color: var(--accent);" />
                        @endif

                        <h3 class="text-base">{{ $tile->title_ar }}</h3>

                        @if ($tile->body_ar)
                            <p class="mt-1 text-xs" style="color: var(--text-muted);">{{ $tile->body_ar }}</p>
                        @endif

                        <span class="mt-2 inline-block text-xs" style="color: var(--accent);">
                            {{ __('sanabel.public.know_more') }}
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ---------- the four questions a first donation asks ------------------
         T-327 — a band their page carries and this one never had. Four rows,
         open on the page rather than behind a click, because a visitor who
         has to open each one to see whether it answers them opens none.
    --------------------------------------------------------------------- --}}
    @if ($homeFaq->isNotEmpty())
        <section id="faq" class="mt-10 scroll-mt-24">
            <h2 class="mb-4 text-xl">{{ __('sanabel.public.donor_questions') }}</h2>

            <div class="space-y-3">
                @foreach ($homeFaq as $question)
                    <article class="card">
                        <h3 class="text-base">{{ $question->title_ar }}</h3>

                        @if ($question->body_ar)
                            <p class="mt-2 text-sm leading-relaxed" style="color: var(--text-muted);">
                                {{ $question->body_ar }}
                            </p>
                        @endif
                    </article>
                @endforeach
            </div>

            <a href="{{ route('page', 'faq') }}"
               class="mt-4 inline-flex items-center gap-1 text-sm no-underline" style="color: var(--accent);">
                {{ __('sanabel.public.all_faq') }}
                <x-brand.icon name="chevron-left" class="h-4 w-4" />
            </a>
        </section>
    @endif

    {{-- ---------- the news --------------------------------------------------
         T-327 — it used to be a third of a row beside the association's two
         cards. Their page gives it a band of its own at the foot, so it has
         one.
    --------------------------------------------------------------------- --}}
    <section id="news" class="mt-10 scroll-mt-24">
        <div class="flex items-end justify-between gap-3">
            <div>
                <h2 class="text-xl">{{ __('sanabel.public.news_updates') }}</h2>
                <p class="mt-1 text-sm" style="color: var(--text-muted);">
                    {{ __('sanabel.public.news_lead') }}
                </p>
            </div>

            <a href="{{ route('news') }}" class="shrink-0 text-sm no-underline" style="color: var(--accent);">
                {{ __('sanabel.public.more') }}
            </a>
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            @forelse ($posts as $post)
                <article class="card flex items-start gap-3">
                    @if ($post->image)
                        <img src="{{ Storage::disk('public')->url($post->image) }}" alt=""
                             class="h-16 w-24 shrink-0 rounded-lg object-cover" loading="lazy">
                    @endif

                    <div class="min-w-0">
                        <a href="{{ route('post', $post->slug) }}" class="text-sm font-medium no-underline">
                            {{ $post->title_ar }}
                        </a>
                        <p class="tabular mt-1 text-xs" style="color: var(--text-muted);" dir="ltr">
                            {{ $post->published_at?->format('Y/m/d') }}
                        </p>
                    </div>
                </article>
            @empty
                <p class="card text-sm sm:col-span-2" style="color: var(--text-muted);">
                    {{ __('sanabel.public.no_news') }}
                </p>
            @endforelse
        </div>
    </section>
@endsection
