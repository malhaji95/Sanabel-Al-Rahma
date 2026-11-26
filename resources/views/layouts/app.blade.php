<!DOCTYPE html>
{{-- Arabic, right-to-left, on every page. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ config('brand.colors.primary') }}">
    <meta name="description" content="@yield('description', config('brand.tagline_ar'))">

    <title>@hasSection('title')@yield('title') — {{ __('sanabel.app_name') }}@else{{ __('sanabel.app_name') }}@endif</title>

    {{-- The card a shared link draws in WhatsApp or on Facebook. It carries the
         platform's own name and artwork and a general line about the kind of
         help needed — never a family's name, picture, area detail or document.
         A page overrides only the title and the line; the image is always ours. --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ __('sanabel.app_name') }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:title" content="@yield('share_title', __('sanabel.app_name'))">
    <meta property="og:description" content="@yield('share_description', config('brand.tagline_ar'))">
    <meta property="og:image" content="{{ url(config('brand.logo.full')) }}">
    <meta name="twitter:card" content="summary_large_image">

    {{-- The symbol alone, as the identity guide requires for icons. --}}
    <link rel="icon" type="image/png" href="{{ config('brand.icons.favicon') }}">
    <link rel="apple-touch-icon" href="{{ config('brand.icons.apple_touch') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen">
    {{-- Colour scheme is resolved before paint so a dark-mode reader never sees a flash of light. --}}
    <script>
        (() => {
            const stored = localStorage.getItem('sanabel-theme')
            const dark = stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches
            document.documentElement.classList.toggle('dark', dark)
        })()
    </script>

    <a href="#main" class="btn-primary sr-only focus:not-sr-only focus:absolute focus:top-3 focus:start-3 focus:z-50">
        {{ __('sanabel.public.skip_to_content') }}
    </a>

    <header
        x-data="{ drawer: false }"
        x-on:keydown.escape.window="drawer = false"
        class="sticky top-0 z-40 border-b backdrop-blur"
        style="border-color: var(--border); background-color: color-mix(in srgb, var(--page) 85%, transparent);"
    >
        <div class="mx-auto flex max-w-6xl items-center gap-4 px-4 py-3">
            {{-- T-99 — the association asked on 15 October for the approved
                 logo itself in the bar and the footer, not the name-only
                 version. The supplied artwork is a stacked lockup (symbol over
                 the name over the line), so in a bar it is given height rather
                 than width: 48px keeps the name legible without making the
                 sticky header a third of a phone screen. A horizontal lockup,
                 if the designer supplies one, is one path change here.

                 T-112 — larger from a laptop upwards, where the bar has the
                 room and the association asked for it. The phone keeps 48. --}}
            <x-brand.logo-plate variant="full" :height="48" :large-height="68" :href="route('home')" class="shrink-0" />

            <nav class="ms-auto flex items-center gap-1 text-sm" aria-label="{{ __('sanabel.public.main_nav') }}">
                {{-- T-89 — the bar is rows the association edits, so adding a
                     link is an insert and not a release. --}}
                @foreach ($navLinks as $link)
                    @php($here = request()->getPathInfo() === rtrim($link->link, '/') || ($link->link === '/' && request()->getPathInfo() === '/'))
                    <a
                        href="{{ $link->link }}"
                        @class([
                            'hidden rounded-lg px-3 py-2 no-underline transition sm:block',
                            'font-semibold' => $here,
                        ])
                        style="color: {{ $here ? 'var(--accent)' : 'var(--text-muted)' }};"
                        @if ($here) aria-current="page" @endif
                    >{{ $link->title_ar }}</a>
                @endforeach

                {{-- T-147 — the basket says how many families are in it, the
                     way a shop's does. Signed-in donors only; it draws nothing
                     when the basket is empty. --}}
                @auth
                    <livewire:basket-count />
                @endauth

                {{-- T-327 — their approved bar ends with «تبرع الآن» and the
                     drawer button, in that order. The sticky button of T-103
                     stays for the long scroll; this one is for a reader who
                     has decided before scrolling at all. --}}
                <a href="{{ route('donate.general') }}"
                   class="btn-primary hidden shrink-0 text-sm no-underline sm:inline-flex">
                    {{ __('sanabel.public.donate_now') }}
                </a>

                <x-theme-toggle />

                <x-account-menu />

                <button type="button" x-on:click="drawer = true"
                        class="btn-secondary inline-flex shrink-0 items-center !px-2.5"
                        :aria-expanded="drawer ? 'true' : 'false'"
                        aria-label="{{ __('sanabel.public.open_menu') }}">
                    <x-brand.icon name="menu" class="h-5 w-5" />
                </button>
            </nav>
        </div>

        {{-- Mobile navigation, kept out of the header row so the logo keeps its space. --}}
        <nav class="flex gap-1 overflow-x-auto px-4 pb-2 text-sm sm:hidden" aria-label="{{ __('sanabel.public.main_nav') }}">
            @foreach ($navLinks as $link)
                @php($here = request()->getPathInfo() === rtrim($link->link, '/'))
                <a
                    href="{{ $link->link }}"
                    class="whitespace-nowrap rounded-lg px-3 py-1.5 no-underline"
                    style="color: {{ $here ? 'var(--accent)' : 'var(--text-muted)' }};"
                >{{ $link->title_ar }}</a>
            @endforeach
        </nav>

        {{-- T-327 — the drawer their page opens from «☰». Seven doors, the
             ones they listed, each to the page or the band that answers it.
             It is Alpine only: with no script the button simply does nothing
             and every one of these doors is still reachable from the bar, the
             foot of the page or the bands themselves. --}}
        <div x-cloak x-show="drawer" class="fixed inset-0 z-50" role="dialog" aria-modal="true">
            <div x-show="drawer" x-transition.opacity class="absolute inset-0 bg-black/40"
                 x-on:click="drawer = false"></div>

            <nav
                x-show="drawer"
                x-transition:enter="transition duration-200" x-transition:enter-start="opacity-0"
                x-transition:leave="transition duration-150" x-transition:leave-end="opacity-0"
                class="absolute inset-y-0 end-0 flex w-80 max-w-[85vw] flex-col gap-1 overflow-y-auto p-4 shadow-xl"
                style="background-color: var(--card);"
                aria-label="{{ __('sanabel.public.open_menu') }}"
            >
                <button type="button" x-on:click="drawer = false"
                        class="btn-secondary mb-2 self-start !px-2.5"
                        aria-label="{{ __('sanabel.public.close_menu') }}">
                    <x-brand.icon name="x" class="h-5 w-5" />
                </button>

                @foreach ([
                    ['home', url('/'), 'home'],
                    ['giving', url('/#zakat'), 'heart'],
                    ['health', url('/#health'), 'medical'],
                    ['about', url('/about'), 'info'],
                    ['governance', url('/internal-oversight'), 'scales'],
                    ['faq', url('/faq'), 'file-search'],
                    ['contact', url('/contact'), 'phone'],
                ] as [$key, $href, $icon])
                    <a href="{{ $href }}" x-on:click="drawer = false"
                       class="flex items-center gap-3 rounded-lg px-3 py-3 no-underline"
                       style="color: var(--text);">
                        <x-brand.icon :name="$icon" class="h-5 w-5" style="color: var(--accent);" />
                        {{ __('sanabel.public.menu.'.$key) }}
                    </a>
                @endforeach
            </nav>
        </div>
    </header>

    {{-- T-108 — room at the foot for the floating donate button, so it never
         comes to rest on top of the last card on the page. --}}
    <main id="main" class="mx-auto max-w-6xl px-4 pb-24 pt-8 sm:pb-8">
        @if (session('status'))
            <div class="alert-success mb-6" role="status">{{ session('status') }}</div>
        @endif

        @yield('content')
        {{ $slot ?? '' }}
    </main>

    {{-- T-327 — their approved page closes on a deep green band rather than a
         white one with a hairline over it. The lockup is the on-dark artwork,
         which is what that variant is for, and the links sit beside it instead
         of under it.

         The social circles their file draws are not here: nothing in this
         system holds the association's own accounts, and five icons leading
         nowhere are worse than none. They go in the day the addresses do. --}}
    <footer id="footer" class="mt-16 scroll-mt-24" style="background-color: var(--brand-green-dark, #103a28); color: #eaf5ee;">
        <div class="mx-auto max-w-6xl px-4 py-10">
            <div class="flex flex-col items-start gap-6 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    {{-- The footer has the room the bar does not, so the lockup
                         is shown at a size where its own tagline can be read.
                         The line beneath it is therefore dropped: the artwork
                         already carries it. --}}
                    {{-- The band is deep green whichever scheme the reader
                         is on, so the on-dark artwork is named here rather
                         than left to the component's own light-and-dark
                         switch, which would draw the dark-ink lockup on it. --}}
                    <img src="{{ asset(config('brand.logo.on_dark.full')) }}"
                         alt="{{ __('sanabel.app_name') }}" height="120"
                         class="h-[120px] w-auto">

                </div>

                {{-- The footer the design asks for, as rows: how we work,
                     who we are, the privacy policy, the oversight committee,
                     the terms, the questions, and the financial transparency
                     page. Each is a link the association edits or removes. --}}
                <nav class="grid max-w-xl grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-2"
                     aria-label="{{ __('sanabel.public.footer_nav') }}">
                    @foreach ($footerLinks as $link)
                        <a href="{{ $link->link }}" class="no-underline" style="color: #cfe6d8;">{{ $link->title_ar }}</a>
                    @endforeach
                </nav>
            </div>

            <div class="rule-gold my-6"></div>

            <p class="text-xs" style="color: #b7d4c3;">
                {{ __('sanabel.app_name') }} — {{ __('sanabel.public.footer') }}
            </p>
        </div>
    </footer>

    {{--
        T-103 — the donate button that follows the page.

        Asked for on 15 October: «نحتاج سهم تبع الدفع المباشر يمشي معه في
        الشاشة لما يطلع في الشاشة أو ينزل». It appears once the visitor has
        scrolled past the offer at the top, so it is an second chance rather
        than a thing in the way, and it is not drawn on the page it leads to.

        It is a link, not a script: with Alpine unavailable it still works,
        it is simply always visible.
    --}}
    @unless (request()->routeIs('donate.general'))
        <div
            x-data="{ shown: false }"
            x-init="shown = window.scrollY > 400; window.addEventListener('scroll', () => shown = window.scrollY > 400, { passive: true })"
            x-show="shown"
            x-cloak
            x-transition.opacity
            class="fixed bottom-5 end-5 z-40 hidden print:hidden sm:block"
        >
            <a href="{{ route('donate.general') }}"
               class="btn-primary inline-flex items-center gap-2 !rounded-full text-sm shadow-lg no-underline sm:text-base">
                <x-brand.icon name="hand-heart" class="h-4 w-4 sm:h-5 sm:w-5" />
                {{ __('sanabel.public.donate_sticky') }}
            </a>
        </div>
    @endunless

    {{-- T-327 — the bar their page puts at the foot of a telephone: five
         doors a donor uses over and over, where a thumb reaches them. It is
         drawn on the phone only, which is why the sticky donate button above
         is now from `sm` upwards — the two were landing on one another.

         `pb-24` on <main> is what keeps the last card clear of it. --}}
    <nav class="fixed inset-x-0 bottom-0 z-40 border-t print:hidden sm:hidden"
         style="border-color: var(--border); background-color: var(--card);"
         aria-label="{{ __('sanabel.public.main_nav') }}">
        {{-- A household that signs in is here to read its own file, not to
             fill a basket (T-194). The bar follows the same rule the account
             menu already follows, so the two can never disagree. --}}
        @php($household = auth()->check() && auth()->user()->hasRole('beneficiary'))

        <div @class(['grid', 'grid-cols-3' => $household, 'grid-cols-5' => ! $household])>
            @foreach ($household ? [
                ['file', route('family.portal'), 'person'],
                ['cases', route('cases.browse'), 'family'],
                ['contact', route('page', 'contact'), 'phone'],
            ] : [
                ['account', route('donor.portal'), 'person'],
                ['cases', route('cases.browse'), 'family'],
                ['basket', route('donor.basket'), 'card'],
                ['gifts', route('gift.give'), 'gift'],
                ['favourites', route('donor.favourites'), 'heart'],
            ] as [$key, $href, $icon])
                <a href="{{ $href }}"
                   class="flex flex-col items-center gap-1 py-2 text-[11px] no-underline"
                   style="color: var(--text-muted);">
                    <x-brand.icon :name="$icon" class="h-5 w-5" />
                    {{ __('sanabel.public.tabbar.'.$key) }}
                </a>
            @endforeach
        </div>
    </nav>

    @livewireScripts
</body>
</html>
