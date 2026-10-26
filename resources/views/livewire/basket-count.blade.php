{{-- A basket with nothing in it is not announced: the row in the account menu
     is where an empty basket belongs. --}}
<div>
    @if ($count > 0)
        <a
            href="{{ route('donor.basket') }}"
            class="relative flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm no-underline"
            style="color: var(--accent);"
            aria-label="{{ __('sanabel.public.basket') }} — {{ __('sanabel.public.basket_count', ['count' => $count]) }}"
        >
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path d="M4 7h16l-1.4 11.2a2 2 0 0 1-2 1.8H7.4a2 2 0 0 1-2-1.8L4 7Z" stroke-linejoin="round"/>
                <path d="M9 7V5.5a3 3 0 0 1 6 0V7" stroke-linecap="round"/>
            </svg>

            <span class="tabular font-semibold">{{ $count }}</span>

            <span class="hidden sm:inline">{{ __('sanabel.public.basket_items') }}</span>
        </a>
    @endif
</div>
