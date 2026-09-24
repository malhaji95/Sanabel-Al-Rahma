<div>
    @if ($remaining <= 0)
        <p class="text-sm" style="color: var(--text-muted);">{{ __('sanabel.public.fully_covered') }}</p>
    @else
        @if (count($openMonths) > 1)
            <label class="mb-2 block text-xs" for="month-{{ $fileNumber }}">
                <span style="color: var(--text-muted);">{{ __('sanabel.basket.coverage_month') }}</span>
                <select id="month-{{ $fileNumber }}" wire:model="coverageMonth" class="field mt-1">
                    @foreach ($openMonths as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        @endif

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
