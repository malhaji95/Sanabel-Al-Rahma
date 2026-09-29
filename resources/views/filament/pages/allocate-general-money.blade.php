<x-filament-panels::page>
    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('sanabel.general.balance') }}</p>
        <p class="mt-1 text-3xl font-bold text-primary-600 dark:text-primary-400">
            {{ number_format($balance) }}
            <span class="text-sm font-normal text-gray-500">{{ config('sanabel.currency') }}</span>
        </p>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('sanabel.general.help') }}</p>
    </div>

    @if ($donations->isEmpty())
        <div class="fi-section rounded-xl bg-white p-6 text-sm text-gray-500 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:text-gray-400 dark:ring-white/10">
            {{ __('sanabel.general.none') }}
        </div>
    @else
        <div class="fi-section overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3 text-start font-medium">{{ __('sanabel.donation.transaction_ref') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ __('sanabel.donation.donor') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ __('sanabel.donation.amount') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ __('sanabel.general.remaining') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ __('sanabel.donation.verified_at') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($donations as $donation)
                        <tr @class(['bg-primary-50 dark:bg-primary-950/30' => $donationId === $donation['id']])>
                            <td class="px-4 py-3 font-mono text-xs" dir="ltr">{{ $donation['transaction_ref'] }}</td>
                            <td class="px-4 py-3">{{ $donation['donor'] ?? '—' }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ number_format($donation['amount']) }}</td>
                            <td class="px-4 py-3 font-semibold tabular-nums text-primary-600 dark:text-primary-400">
                                {{ number_format($donation['remaining']) }}
                            </td>
                            <td class="px-4 py-3 text-gray-500">{{ $donation['verified_at'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-end">
                                <x-filament::button size="sm" wire:click="select({{ $donation['id'] }})">
                                    {{ __('sanabel.general.choose') }}
                                </x-filament::button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($donationId)
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="mb-4 text-base font-semibold">{{ __('sanabel.general.allocate_heading') }}</h3>

            <form wire:submit="allocate" class="space-y-4">
                {{ $this->form }}

                <x-filament::button type="submit">{{ __('sanabel.general.allocate') }}</x-filament::button>
            </form>
        </div>
    @endif
</x-filament-panels::page>
