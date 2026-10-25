<x-filament-panels::page>
    {{--
        T-125 — the two queues, by whoever is holding them and by how long.
        The hours are measured from a different column in each table, and
        each table says which one rather than leaving it to be guessed.
    --}}
    @php($tables = [
        [
            'heading' => __('sanabel.follow_up.awaiting_delegate'),
            'who' => __('sanabel.follow_up.opened_by'),
            'since' => __('sanabel.follow_up.since_opened'),
            'rows' => $this->awaitingDelegate(),
        ],
        [
            'heading' => __('sanabel.follow_up.awaiting_supervisor'),
            'who' => __('sanabel.follow_up.area'),
            'since' => __('sanabel.follow_up.since_field_sign_off'),
            'rows' => $this->awaitingSupervisor(),
        ],
    ])

    @foreach ($tables as $table)
        <x-filament::section :heading="$table['heading']" :description="$table['since']">
            @if (empty($table['rows']))
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('sanabel.follow_up.nothing_waiting') }}
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-xs text-gray-500 dark:text-gray-400">
                                <th class="p-2 text-start">{{ $table['who'] }}</th>
                                <th class="p-2 text-start">{{ __('sanabel.follow_up.total') }}</th>
                                <th class="p-2 text-start">{{ __('sanabel.follow_up.late') }}</th>
                                <th class="p-2 text-start">{{ __('sanabel.follow_up.very_late') }}</th>
                                <th class="p-2 text-start">{{ __('sanabel.follow_up.oldest') }}</th>
                                <th class="p-2 text-start"></th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($table['rows'] as $row)
                                <tr class="border-t border-gray-200 dark:border-gray-700">
                                    <td class="p-2">
                                        <span class="font-medium">{{ $row['label'] }}</span>

                                        @if ($row['people'])
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">
                                                {{ $row['people'] }}
                                            </span>
                                        @endif
                                    </td>

                                    <td class="p-2 tabular-nums">{{ $row['total'] }}</td>

                                    <td class="p-2 tabular-nums">
                                        @if ($row['late'])
                                            <span class="font-semibold text-warning-600 dark:text-warning-400">
                                                {{ $row['late'] }}
                                            </span>
                                        @else
                                            —
                                        @endif
                                    </td>

                                    <td class="p-2 tabular-nums">
                                        @if ($row['very_late'])
                                            <span class="font-semibold text-danger-600 dark:text-danger-400">
                                                {{ $row['very_late'] }}
                                            </span>
                                        @else
                                            —
                                        @endif
                                    </td>

                                    <td class="p-2 tabular-nums">
                                        {{ __('sanabel.follow_up.hours', ['count' => $row['oldest_hours']]) }}
                                    </td>

                                    <td class="p-2">
                                        <a href="{{ $row['url'] }}" class="text-primary-600 dark:text-primary-400">
                                            {{ __('sanabel.follow_up.open_list') }}
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
