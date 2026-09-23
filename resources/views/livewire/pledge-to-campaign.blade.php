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

    @if ($notice)
        <p class="mt-2 text-xs font-medium" style="color: var(--accent);">{{ $notice }}</p>
    @endif
</div>
