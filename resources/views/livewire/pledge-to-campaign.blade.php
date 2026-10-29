<div class="mt-auto pt-4">
    @if ($remaining <= 0)
        <p class="text-sm" style="color: var(--text-muted);">{{ __('sanabel.public.campaign_closed') }}</p>
    @else
        <form wire:submit="pledge" class="flex gap-2">
            <label class="sr-only" for="pledge-{{ $campaignId }}">{{ __('sanabel.public.amount') }}</label>
            <input
                id="pledge-{{ $campaignId }}"
                type="number"
                wire:model="amount"
                min="1"
                max="{{ $remaining }}"
                inputmode="numeric"
                class="field tabular"
            >
            <button type="submit" class="btn-primary whitespace-nowrap">
                <span wire:loading.remove wire:target="pledge">{{ __('sanabel.public.support_campaign') }}</span>
                <span wire:loading wire:target="pledge">…</span>
            </button>
        </form>

        @error('amount')
            <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
        @enderror
    @endif

    {{-- T-190 — the two ways on, as a family's card has offered since T-147.
         Neither is taken for the donor, and the page has not moved. --}}
    @if ($added)
        <div class="mt-2 rounded-xl p-3" style="background: var(--surface-muted, rgba(0,0,0,.03));">
            <p class="text-xs font-medium" style="color: var(--accent);">{{ $notice }}</p>

            <div class="mt-2 flex flex-wrap gap-2">
                <button type="button" wire:click="keepGiving" class="btn-secondary flex-1 justify-center text-sm">
                    {{ __('sanabel.public.continue_donating') }}
                </button>

                <a href="{{ route('donor.basket') }}" class="btn-primary flex-1 justify-center text-sm no-underline">
                    {{ __('sanabel.public.complete_donation') }}
                </a>
            </div>
        </div>
    @elseif ($notice)
        <p class="mt-2 text-xs font-medium" style="color: var(--accent);" x-data x-init="$nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'center' }))">{{ $notice }}</p>
    @endif
</div>
