{{--
    T-183 — the figures, asked again every few seconds while they are on the
    screen. `.visible` stops the polling the moment the strip scrolls away,
    so a page left open overnight does not keep asking.

    A single root element: a Livewire component whose root is an `@if` is
    never attached, and the strip would freeze on its first answer.
--}}
<div wire:poll.visible.15s>
    @if ($figures->isNotEmpty())
        <section class="card mt-6 !p-0" aria-label="{{ __('sanabel.public.stats') }}" aria-live="polite">
            <div @class([
                'grid grid-cols-2',
                'sm:grid-cols-3' => $figures->count() === 3,
                'sm:grid-cols-4' => $figures->count() === 4,
                'sm:grid-cols-5' => $figures->count() >= 5,
            ])>
                @foreach ($figures as [$key, $value, $label, $icon, $colour, $isMoney])
                    <div class="border-b border-s p-4 text-center last:border-b-0 sm:border-b-0"
                         style="border-color: var(--border);">
                        <x-brand.icon :name="$icon" class="mx-auto mb-1.5 h-6 w-6" style="color: {{ $colour }};" />
                        <p class="tabular text-2xl font-bold" style="color: {{ $colour }};">
                            {{ number_format($value) }}
                            @if ($isMoney)
                                <span class="text-xs font-normal" style="color: var(--text-muted);">{{ \App\Services\Money::label() }}</span>
                            @endif
                        </p>
                        <p class="mt-1 text-xs" style="color: var(--text-muted);">{{ $label }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>
