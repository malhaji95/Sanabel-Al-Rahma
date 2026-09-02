<div class="space-y-6">
    <div>
        <h1 class="text-2xl">{{ __('sanabel.public.basket') }}</h1>
        @if ($items->isNotEmpty())
            <p class="mt-1 text-sm" style="color: var(--text-muted);">
                {{ __('sanabel.public.basket_count', ['count' => $items->count()]) }}
            </p>
        @endif
    </div>

    @if ($error)
        <div class="alert-danger" role="alert">
            <svg class="mt-0.5 h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01" stroke-linecap="round"/>
            </svg>
            <span>{{ $error }}</span>
        </div>
    @endif

    @if ($notice)
        <div class="alert-success" role="status">
            <svg class="mt-0.5 h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span>{{ $notice }}</span>
        </div>
    @endif

    @if ($items->isEmpty())
        <x-empty-state :title="__('sanabel.basket.empty')" :body="__('sanabel.public.basket_empty_help')">
            <a href="{{ route('cases.browse') }}" class="btn-primary mt-2 no-underline">
                {{ __('sanabel.public.browse_cases') }}
            </a>
        </x-empty-state>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($items as $item)
                <x-masked-case-card :case="$item['case']">
                    <div class="flex items-center justify-between gap-2">
                        <span class="tabular font-semibold">
                            {{ number_format($item['amount']) }}
                            <span class="text-xs font-normal" style="color: var(--text-muted);">{{ config('sanabel.currency') }}</span>
                        </span>
                        <button
                            type="button"
                            wire:click="removeItem({{ $item['id'] }})"
                            class="btn-ghost !px-2 !py-1 text-sm hover:!text-red-600"
                        >{{ __('sanabel.public.remove') }}</button>
                    </div>
                </x-masked-case-card>
            @endforeach
        </div>

        <div class="card space-y-5">
            <div class="flex items-baseline justify-between">
                <span class="text-lg font-medium">{{ __('sanabel.public.total') }}</span>
                <span class="tabular text-2xl font-bold" style="color: var(--accent);">
                    {{ number_format($total) }}
                    <span class="text-sm font-normal" style="color: var(--text-muted);">{{ config('sanabel.currency') }}</span>
                </span>
            </div>

            @if ($basket->isLive())
                <div class="alert-warning" role="status">
                    <svg class="mt-0.5 h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2" stroke-linecap="round"/>
                    </svg>
                    <span>{{ __('sanabel.public.reserved_until', ['time' => $basket->reserved_until->translatedFormat('Y-m-d H:i')]) }}</span>
                </div>

                {{-- No automatic transfers exist: a human moves the money, the system records it. --}}
                <form wire:submit="recordTransfer" class="space-y-4">
                    <p class="text-sm leading-relaxed" style="color: var(--text-muted);">
                        {{ __('sanabel.public.transfer_instructions') }}
                    </p>

                    <div>
                        <label class="field-label" for="transaction-ref">
                            {{ __('sanabel.donation.transaction_ref') }}
                        </label>
                        <input id="transaction-ref" type="text" wire:model="transactionRef" class="field tabular" dir="ltr">
                        @error('transactionRef')
                            <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <button type="submit" class="btn-primary">
                        <span wire:loading.remove wire:target="recordTransfer">{{ __('sanabel.public.record_transfer') }}</span>
                        <span wire:loading wire:target="recordTransfer">…</span>
                    </button>
                </form>
            @else
                <div>
                    <button type="button" wire:click="reserve" class="btn-primary">
                        {{ __('sanabel.public.reserve') }}
                    </button>
                    <p class="mt-2.5 text-sm" style="color: var(--text-muted);">{{ __('sanabel.public.reserve_help') }}</p>
                </div>
            @endif
        </div>
    @endif
</div>
