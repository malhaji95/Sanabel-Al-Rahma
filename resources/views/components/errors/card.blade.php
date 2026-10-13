{{--
    T-91 — what a visitor meets when a page is not there, or is not theirs.

    Until now it was the server's own «404 Not Found», in English, on a white
    page, with nothing to click. A charity's site is read by people who do not
    read English and who arrived from a link somebody forwarded them.
--}}
@props(['icon' => 'info', 'title', 'body'])

<section class="card mx-auto max-w-xl text-center">
    <x-brand.icon :name="$icon" class="mx-auto h-10 w-10" style="color: var(--accent);" />

    <h1 class="mt-4 text-xl">{{ $title }}</h1>

    <p class="mt-3 text-sm leading-relaxed" style="color: var(--text-muted);">{{ $body }}</p>

    <div class="mt-6 flex flex-wrap justify-center gap-3">
        <a href="{{ route('home') }}" class="btn-primary no-underline">{{ __('sanabel.errors.home') }}</a>
        <a href="{{ route('cases.browse') }}" class="btn-secondary no-underline">{{ __('sanabel.public.browse_cases') }}</a>
    </div>
</section>
