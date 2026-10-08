<x-filament-widgets::widget>
    <x-filament::section :heading="__('sanabel.dashboard.coverage_by_region')">
        @php($rows = $this->getRows())

        @if (empty($rows))
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('sanabel.dashboard.coverage_by_region_empty') }}
            </p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 text-start font-medium">{{ __('sanabel.beneficiary.region') }}</th>
                        <th class="py-2 text-start font-medium">{{ __('sanabel.dashboard.families') }}</th>
                        <th class="py-2 text-start font-medium">{{ __('sanabel.dashboard.total_need') }}</th>
                        <th class="py-2 text-start font-medium">{{ __('sanabel.dashboard.total_paid') }}</th>
                        <th class="py-2 text-start font-medium">{{ __('sanabel.beneficiary.coverage') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                            <td class="py-2 font-medium">{{ $row['region'] }}</td>
                            <td class="py-2 tabular-nums">{{ number_format($row['families']) }}</td>
                            <td class="py-2 tabular-nums">{{ number_format($row['need']) }}</td>
                            <td class="py-2 tabular-nums">{{ number_format($row['paid']) }}</td>
                            <td class="py-2">
                                <x-filament::badge :color="match (true) {
                                    $row['percent'] >= 100 => 'success',
                                    $row['percent'] >= 61 => 'info',
                                    $row['percent'] >= 41 => 'warning',
                                    default => 'danger',
                                }">{{ $row['percent'] }}%</x-filament::badge>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
