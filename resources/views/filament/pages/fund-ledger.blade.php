<x-filament-panels::page>
    {{-- T-324 — one fund at a time, in date order, with the balance after
         every movement. The figures are read from the money's own tables; this
         page keeps nothing. --}}
    <div class="flex flex-wrap gap-2">
        @foreach ($this->funds() as $fund)
            <x-filament::button
                size="sm"
                :color="$fund->key === $this->fundKey ? 'primary' : 'gray'"
                wire:click="showFund('{{ $fund->key }}')"
            >
                {{ $fund->name_ar }}
            </x-filament::button>
        @endforeach
    </div>

    <x-filament::section
        :heading="$this->fund()?->name_ar"
        :description="__('sanabel.ledger.note')"
    >
        <x-slot name="headerEnd">
            <span class="text-lg font-bold tabular-nums">
                {{ number_format($this->balance()) }}
                {{ \App\Services\Money::short() }}
            </span>
        </x-slot>

        @php($movements = $this->movements())

        @if ($movements === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('sanabel.ledger.nothing') }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr>
                            <th class="p-2 text-start">{{ __('sanabel.ledger.when') }}</th>
                            <th class="p-2 text-start">{{ __('sanabel.ledger.what') }}</th>
                            <th class="p-2 text-start">{{ __('sanabel.donation.transaction_ref') }}</th>
                            <th class="p-2 text-start">{{ __('sanabel.ledger.movement') }}</th>
                            <th class="p-2 text-start">{{ __('sanabel.ledger.balance') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (array_reverse($movements) as $movement)
                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                <td class="p-2 tabular-nums">
                                    {{ $movement['at']->format('Y-m-d') }}
                                    @if ($movement['closed'])
                                        <x-filament::badge color="gray" class="mt-1">
                                            {{ __('sanabel.period.statuses.closed') }}
                                        </x-filament::badge>
                                    @endif
                                </td>
                                <td class="p-2">
                                    {{ __('sanabel.ledger.kinds.'.$movement['kind']) }}
                                    @if ($movement['subject'])
                                        <span class="text-xs opacity-70 tabular-nums">{{ $movement['subject'] }}</span>
                                    @endif
                                </td>
                                <td class="p-2" dir="ltr">{{ $movement['ref'] ?? '—' }}</td>
                                <td class="p-2 tabular-nums {{ $movement['amount'] < 0 ? 'text-danger-600' : 'text-success-600' }}">
                                    {{ number_format($movement['amount']) }}
                                </td>
                                <td class="p-2 font-medium tabular-nums">{{ number_format($movement['balance']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
