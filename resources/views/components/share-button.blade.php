{{--
    Sending an opportunity on. The link opens that opportunity, and the preview
    card beside it is the platform's own — no family detail travels with it.

    The device's own share sheet is offered where the browser has one, which on
    a phone is every app the person already uses; the explicit buttons are the
    fallback for a desktop browser that has none.
--}}
@props(['url', 'title'])

@php
    $encodedUrl = rawurlencode($url);
    $encodedTitle = rawurlencode($title);
@endphp

<div x-data="{
        open: false,
        copied: false,
        hasNative: typeof navigator !== 'undefined' && !! navigator.share,
        async native() {
            try {
                await navigator.share({ title: @js($title), url: @js($url) });
                this.open = false;
            } catch (e) {
                // The person dismissed the sheet; nothing to report.
            }
        },
        async copy() {
            try {
                await navigator.clipboard.writeText(@js($url));
            } catch (e) {
                // Older browsers, and any page not served over https.
                const field = document.createElement('textarea');
                field.value = @js($url);
                document.body.appendChild(field);
                field.select();
                document.execCommand('copy');
                field.remove();
            }
            this.copied = true;
            setTimeout(() => this.copied = false, 2500);
        },
     }"
     class="relative inline-block">

    <button type="button" class="btn-secondary" x-on:click="hasNative ? native() : (open = ! open)">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
            <path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/>
        </svg>
        {{ __('sanabel.share.action') }}
    </button>

    <div x-show="open" x-cloak x-on:click.outside="open = false"
         class="card absolute z-20 mt-2 w-64 end-0 p-3">
        <p class="mb-3 text-sm font-medium">{{ __('sanabel.share.heading') }}</p>

        <div class="grid grid-cols-4 gap-2 text-center text-xs">
            <a class="no-underline" target="_blank" rel="noopener"
               href="https://wa.me/?text={{ $encodedTitle }}%20{{ $encodedUrl }}">
                <span class="block rounded-xl border p-2">واتساب</span>
            </a>
            <a class="no-underline" target="_blank" rel="noopener"
               href="https://www.facebook.com/sharer/sharer.php?u={{ $encodedUrl }}">
                <span class="block rounded-xl border p-2">فيسبوك</span>
            </a>
            <a class="no-underline" target="_blank" rel="noopener"
               href="https://twitter.com/intent/tweet?text={{ $encodedTitle }}&url={{ $encodedUrl }}">
                <span class="block rounded-xl border p-2">X</span>
            </a>
            <a class="no-underline"
               href="mailto:?subject={{ $encodedTitle }}&body={{ $encodedUrl }}">
                <span class="block rounded-xl border p-2">البريد</span>
            </a>
        </div>

        <button type="button" class="btn-secondary mt-3 w-full justify-center" x-on:click="copy()">
            <span x-show="! copied">{{ __('sanabel.share.copy_link') }}</span>
            <span x-show="copied" x-cloak style="color: var(--accent);">{{ __('sanabel.share.copied') }}</span>
        </button>
    </div>
</div>
