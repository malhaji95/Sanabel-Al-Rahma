<div class="mt-4">
    @if ($remaining <= 0)
        <p class="text-sm text-slate-500">{{ __('sanabel.public.fully_covered') }}</p>
    @else
        <form wire:submit="add" class="flex gap-2">
            <input
                type="number"
                wire:model="amount"
                min="1"
                max="{{ $remaining }}"
                class="field"
                aria-label="{{ __('sanabel.public.amount') }}"
            >
            <button type="submit" class="btn-primary whitespace-nowrap">
                {{ __('sanabel.public.add') }}
            </button>
        </form>

        @error('amount')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    @endif

    @if ($notice)
        <p class="mt-2 text-xs text-sanabel-700">{{ $notice }}</p>
    @endif
</div>
