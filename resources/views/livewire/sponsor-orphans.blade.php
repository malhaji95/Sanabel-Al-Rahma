{{--
    T-246 — the number of orphans, on the family's one card.

    The count is what a donor needs and the whole of what they get: no
    name, no age, no order of birth, and no way to tell one child from
    another. Which children a donor's sponsorships land on is the
    association's to decide.
--}}
<div class="mt-2 text-sm">
    @if ($notice)
        <p class="font-medium" style="color: var(--accent);">{{ $notice }}</p>
    @endif

    @if ($error)
        <p class="text-red-600 dark:text-red-400">{{ $error }}</p>
    @endif

    @if ($available > 0)
        <p class="tabular">
            {{ __('sanabel.sponsorships.orphans_available', ['count' => $available]) }}
        </p>

        <div class="mt-2 flex items-center gap-2">
            <label class="sr-only" for="sponsor-count-{{ $fileNumber }}">
                {{ __('sanabel.sponsorships.how_many') }}
            </label>

            <select id="sponsor-count-{{ $fileNumber }}" wire:model="count" class="field tabular w-24">
                @for ($n = 1; $n <= $available; $n++)
                    <option value="{{ $n }}">{{ $n }}</option>
                @endfor
            </select>

            <button type="button" wire:click="sponsor" class="btn-primary">
                <span wire:loading.remove wire:target="sponsor">{{ __('sanabel.sponsorships.sponsor_now') }}</span>
                <span wire:loading wire:target="sponsor">…</span>
            </button>
        </div>

        @guest
            <p class="mt-1 text-xs" style="color: var(--text-muted);">
                {{ __('sanabel.sponsorships.needs_account') }}
            </p>
        @endguest
    @elseif (! $notice)
        <p style="color: var(--text-muted);">{{ __('sanabel.sponsorships.all_taken') }}</p>
    @endif
</div>
