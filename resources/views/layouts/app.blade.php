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
        class="sticky top-0 z-40 border-b backdrop-blur"
        style="border-color: var(--border); background-color: color-mix(in srgb, var(--page) 85%, transparent);"
    >
        <div class="mx-auto flex max-w-6xl items-center gap-4 px-4 py-3">
            {{-- The name-only version: a header bar is not room for the full
                 vertical lockup, which appears in the hero instead. --}}
            <x-brand.logo-plate variant="wordmark" :height="32" :href="route('home')" class="shrink-0" />

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

                <x-theme-toggle />

                @auth
                    <a href="{{ route('donor.portal') }}" class="btn-secondary">
                        {{ __('sanabel.public.my_account') }}
                    </a>
                @else
                    <a href="{{ route('login') }}" class="btn-primary no-underline">
                        {{ __('sanabel.public.login') }}
                    </a>
                @endauth
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
    </header>

    <main id="main" class="mx-auto max-w-6xl px-4 py-8">
        @if (session('status'))
            <div class="alert-success mb-6" role="status">{{ session('status') }}</div>
        @endif

        @yield('content')
        {{ $slot ?? '' }}
    </main>

    <footer class="mt-16 border-t" style="border-color: var(--border);">
        <div class="mx-auto max-w-6xl px-4 py-10">
            <div class="flex flex-col items-start gap-6 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <x-brand.logo-plate variant="wordmark" :height="30" />
                    <p class="mt-3 text-sm" style="color: var(--text-muted);">
                        {{ config('brand.tagline_ar') }}
                    </p>
                </div>

                {{-- The footer the design asks for, as rows: how we work,
                     who we are, the privacy policy, the oversight committee,
                     the terms, the questions, and the financial transparency
                     page. Each is a link the association edits or removes. --}}
                <nav class="grid max-w-xl grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-2"
                     aria-label="{{ __('sanabel.public.footer_nav') }}">
                    @foreach ($footerLinks as $link)
                        <a href="{{ $link->link }}" style="color: var(--text-muted);">{{ $link->title_ar }}</a>
                    @endforeach
                </nav>
            </div>

            <div class="rule-gold my-6"></div>

            <p class="text-xs" style="color: var(--text-muted);">
                {{ __('sanabel.app_name') }} — {{ __('sanabel.public.footer') }}
            </p>
        </div>
    </footer>

    @livewireScripts
</body>
</html>
