<!DOCTYPE html>
{{-- Every page is right-to-left Arabic. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('sanabel.app_name'))</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=tajawal:400,500,700" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4">
            <a href="{{ route('home') }}" class="text-lg font-bold text-sanabel-700">
                {{ __('sanabel.app_name') }}
            </a>

            <nav class="flex items-center gap-4 text-sm">
                <a href="{{ route('cases.browse') }}" class="hover:text-sanabel-700">{{ __('sanabel.public.cases') }}</a>
                <a href="{{ route('campaigns.public') }}" class="hover:text-sanabel-700">{{ __('sanabel.public.campaigns') }}</a>
                <a href="{{ route('news') }}" class="hover:text-sanabel-700">{{ __('sanabel.public.news') }}</a>

                @auth
                    <a href="{{ route('donor.portal') }}" class="btn-secondary">{{ __('sanabel.public.my_account') }}</a>
                @else
                    <a href="{{ route('login') }}" class="btn-primary">{{ __('sanabel.public.login') }}</a>
                @endauth
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-8">
        @if (session('status'))
            <div class="mb-6 rounded-lg bg-sanabel-50 px-4 py-3 text-sm text-sanabel-800">
                {{ session('status') }}
            </div>
        @endif

        @yield('content')
        {{ $slot ?? '' }}
    </main>

    <footer class="mt-12 border-t border-slate-200 bg-white">
        <div class="mx-auto max-w-6xl px-4 py-6 text-sm text-slate-500">
            {{ __('sanabel.app_name') }} — {{ __('sanabel.public.footer') }}
        </div>
    </footer>

    @livewireScripts
</body>
</html>
