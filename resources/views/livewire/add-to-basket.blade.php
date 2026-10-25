{{-- T-139 — the typed amount is mirrored into Alpine so the «بشرى خير» line
     below appears with the digit rather than a round trip after it. Livewire
     still owns the value; this only reads it. --}}
<div x-data="{ typed: @js((int) $amount), remaining: {{ $remaining }} }">
    @if ($remaining <= 0)
        {{-- Named, because coverage answers one month and not the family's
             whole future: the next month opens on its own date. --}}
        <p class="text-sm" style="color: var(--text-muted);">
            {{ __('sanabel.public.fully_covered', ['month' => $this->monthLabel()]) }}
        </p>
    @else
        <p class="mb-2 text-xs" style="color: var(--text-muted);">
            {{ __('sanabel.funding.covers_month', ['month' => $this->monthLabel()]) }}
        </p>

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

        {{-- T-139 — «نحتاج لما يجيي بده يتبرع بمبلغ اكبر يكتب بشرى خير هذه
             العائلة يكتمل تمويلها», 28 October. Read from the box as the donor
             types, in Alpine rather than through the server: a line that
             appears a second after the digit is a line nobody connects to
             what they just typed. Livewire still owns the value. --}}
        <form wire:submit="add" class="flex gap-2">
            <label class="sr-only" for="amount-{{ $fileNumber }}">{{ __('sanabel.public.amount') }}</label>
            <input
                id="amount-{{ $fileNumber }}"
                type="number"
                wire:model="amount"
                x-model.number="typed"
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

        {{-- T-78 — the stage is what the box offers; the whole month is one
             click away, and the ceiling is the real remaining need either way. --}}
        @if ($targetRemaining > 0 && $targetRemaining < $remaining)
            <div class="mt-1.5 flex flex-wrap gap-2 text-xs">
                <button type="button" wire:click="$set('amount', {{ $targetRemaining }})"
                        class="underline" style="color: var(--text-muted);">
                    {{ __('sanabel.funding.target_only') }} ({{ number_format($targetRemaining) }})
                </button>
                <button type="button" wire:click="$set('amount', {{ $remaining }})"
                        class="underline" style="color: var(--text-muted);">
                    {{ __('sanabel.funding.full_month') }} ({{ number_format($remaining) }})
                </button>
            </div>
        @endif

        <p
            x-show="typed >= remaining && remaining > 0"
            x-cloak
            class="mt-2 text-xs font-medium leading-relaxed"
            style="color: var(--accent);"
        >
            {{ __('sanabel.funding.good_news_will_complete', [
                'amount' => number_format($remaining),
                'currency' => \App\Services\Money::label(),
            ]) }}
        </p>

        @error('amount')
            <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
        @enderror
    @endif

    @if ($notice)
        <p class="mt-2 text-xs font-medium" style="color: var(--accent);">{{ $notice }}</p>
    @endif
</div>
