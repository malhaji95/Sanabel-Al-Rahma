<div class="space-y-6">
    <h1 class="text-2xl">{{ __('sanabel.public.basket') }}</h1>

    @if ($error)
        <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $error }}</div>
    @endif

    @if ($notice)
        <div class="rounded-lg bg-sanabel-50 px-4 py-3 text-sm text-sanabel-800">{{ $notice }}</div>
    @endif

    @if ($items->isEmpty())
        <div class="card text-center text-slate-500">
            {{ __('sanabel.basket.empty') }}
            <div class="mt-4">
                <a href="{{ route('cases.browse') }}" class="btn-primary">{{ __('sanabel.public.cases') }}</a>
            </div>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($items as $item)
                <x-masked-case-card :case="$item['case']">
                    <div class="mt-4 flex items-center justify-between">
                        <span class="font-medium">
                            {{ number_format($item['amount']) }} {{ config('sanabel.currency') }}
                        </span>
                        <button
                            type="button"
                            wire:click="removeItem({{ $item['id'] }})"
                            class="text-sm text-red-600 hover:underline"
                        >{{ __('sanabel.public.remove') }}</button>
                    </div>
                </x-masked-case-card>
            @endforeach
        </div>

        <div class="card space-y-4">
            <div class="flex items-center justify-between text-lg">
                <span>{{ __('sanabel.public.total') }}</span>
                <span class="font-bold">{{ number_format($total) }} {{ config('sanabel.currency') }}</span>
            </div>

            @if ($basket->isLive())
                <p class="text-sm text-sanabel-700">
                    {{ __('sanabel.public.reserved_until', ['time' => $basket->reserved_until->translatedFormat('Y-m-d H:i')]) }}
                </p>

                {{-- No automatic transfers exist: a human moves the money, the system records it. --}}
                <form wire:submit="recordTransfer" class="space-y-3">
                    <p class="text-sm text-slate-600">{{ __('sanabel.public.transfer_instructions') }}</p>

                    <label class="block text-sm">
                        {{ __('sanabel.donation.transaction_ref') }}
                        <input type="text" wire:model="transactionRef" class="field mt-1">
                    </label>

                    @error('transactionRef')
                        <p class="text-xs text-red-600">{{ $message }}</p>
                    @enderror

                    <button type="submit" class="btn-primary">{{ __('sanabel.public.record_transfer') }}</button>
                </form>
            @else
                <button type="button" wire:click="reserve" class="btn-primary">
                    {{ __('sanabel.public.reserve') }}
                </button>
                <p class="text-sm text-slate-500">{{ __('sanabel.public.reserve_help') }}</p>
            @endif
        </div>
    @endif
</div>
