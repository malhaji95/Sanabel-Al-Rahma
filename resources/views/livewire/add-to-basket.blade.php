<div>
    @if ($remaining <= 0)
        <p class="text-sm" style="color: var(--text-muted);">{{ __('sanabel.public.fully_covered') }}</p>
    @else
        <form wire:submit="add" class="flex gap-2">
            <label class="sr-only" for="amount-{{ $fileNumber }}">{{ __('sanabel.public.amount') }}</label>
            <input
                id="amount-{{ $fileNumber }}"
                type="number"
                wire:model="amount"
                min="1"
                max="{{ $remaining }}"
                inputmode="numeric"
                class="field tabular"
            >
            <button type="submit" class="btn-primary whitespace-nowrap">
                <span wire:loading.remove wire:target="add">{{ __('sanabel.public.add') }}</span>
                <span wire:loading wire:target="add">…</span>
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
