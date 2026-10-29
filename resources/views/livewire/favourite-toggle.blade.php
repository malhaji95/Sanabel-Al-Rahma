{{--
    T-180 — kept, or not kept. One button, two states.

    The wrapper is not decoration. Livewire attaches a component to its single
    root element, and this view began with `@if` at the top level: on a card
    the button was drawn, but it belonged to the browse list around it, so the
    click went there and was answered «Public method [toggle] not found». One
    root element always, and the condition inside it.

    Shown to a signed-in donor only: the list belongs to an account, so there
    is nowhere to keep a family without one. A visitor is shown nothing rather
    than a second invitation to sign in — the card already carries that once,
    under «تبرع الآن», and twice is a nag.
--}}
<div>
    @if (auth()->user()?->donor)
        <button
            type="button"
            wire:click="toggle"
            wire:loading.attr="disabled"
            aria-pressed="{{ $saved ? 'true' : 'false' }}"
            title="{{ $saved ? __('sanabel.favourites.remove') : __('sanabel.favourites.add') }}"
            @class([
                'btn-secondary w-full gap-1.5',
                'border-gold-400 text-gold-700 dark:text-gold-200' => $saved,
            ])
        >
            <svg class="h-4 w-4" viewBox="0 0 24 24"
                 fill="{{ $saved ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.8"
                 aria-hidden="true">
                <path d="m12 3.6 2.6 5.3 5.9.9-4.2 4.1 1 5.8-5.3-2.8-5.3 2.8 1-5.8L3.5 9.8l5.9-.9L12 3.6Z"
                      stroke-linejoin="round"/>
            </svg>

            <span>{{ $saved ? __('sanabel.favourites.saved') : __('sanabel.favourites.add') }}</span>
        </button>
    @endif
</div>
