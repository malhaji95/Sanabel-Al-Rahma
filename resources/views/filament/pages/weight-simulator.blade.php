<x-filament-panels::page>
    {{--
        T-297 — nothing on this page writes. It is the weights in the boxes,
        applied to the factors each study already froze, and the places the
        files would hold if the association entered them.
    --}}
    <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm dark:border-warning-700 dark:bg-warning-950">
        {{ __('sanabel.simulator.nothing_is_written') }}
    </div>

    {{ $this->form }}

    @php($result = $this->result())

    {{--
        Filament's own grid, not bare Tailwind: the panel's stylesheet is
        built from Filament's sources, so a responsive column class written
        only here is swept out of it and the four cards stack in a column.
    --}}
    <x-filament::grid :default="1" :sm="2" :lg="4" class="gap-4">
        @foreach ([
            'total' => $result['total'],
            'up' => $result['up'],
            'down' => $result['down'],
            'same' => $result['same'],
        ] as $key => $value)
            <div class="rounded-xl border bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <div class="text-2xl font-bold">{{ number_format($value) }}</div>
                <div class="text-sm text-gray-500">{{ __('sanabel.simulator.'.$key) }}</div>
            </div>
        @endforeach
    </x-filament::grid>

    <div class="space-y-2 text-sm">
        <p>
            {{ __('sanabel.simulator.sum', ['sum' => rtrim(rtrim(number_format($result['sum'], 4, '.', ''), '0'), '.') ?: '0']) }}
            @if (abs($result['sum'] - 1) > 0.0001)
                <span class="font-bold text-warning-600">{{ __('sanabel.simulator.sum_not_one') }}</span>
            @endif
        </p>

        @if ($result['biggest_rise'])
            <p>{{ __('sanabel.simulator.biggest_rise', [
                'file' => $result['biggest_rise']['file_number'],
                'places' => $result['biggest_rise']['move'],
            ]) }}</p>
        @endif

        @if ($result['biggest_fall'])
            <p>{{ __('sanabel.simulator.biggest_fall', [
                'file' => $result['biggest_fall']['file_number'],
                'places' => abs($result['biggest_fall']['move']),
            ]) }}</p>
        @endif

        @if ($result['stale'] > 0)
            {{--
                A study keeps the weights it was computed with (rule 8), so a
                file studied before the weights last changed is already being
                ranked on an older set. That is not this page's doing and it
                is said out loud rather than left to be discovered.
            --}}
            <p class="text-warning-600">{{ __('sanabel.simulator.stale', ['count' => $result['stale']]) }}</p>
        @endif
    </div>

    @if ($result['total'] === 0)
        <p class="text-gray-500">{{ __('sanabel.simulator.nothing_to_compare') }}</p>
    @else
        {{--
            The figures above count every file; this draws the first hundred
            of them in order. A page that tried to draw some thousands of rows
            would take long enough that the reader would think it had hung.
        --}}
        @php($shown = array_slice($result['rows'], 0, 100))

        @if ($result['total'] > count($shown))
            <p class="text-sm text-gray-500">
                {{ __('sanabel.simulator.showing', ['shown' => count($shown), 'total' => $result['total']]) }}
            </p>
        @endif

        <div class="overflow-x-auto rounded-xl border dark:border-gray-700">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-start dark:bg-gray-800">
                    <tr>
                        <th class="p-2 text-start">{{ __('sanabel.beneficiary.file_number') }}</th>
                        <th class="p-2 text-start">{{ __('sanabel.beneficiary.first_name') }}</th>
                        <th class="p-2 text-start">{{ __('sanabel.simulator.score_now') }}</th>
                        <th class="p-2 text-start">{{ __('sanabel.simulator.score_new') }}</th>
                        <th class="p-2 text-start">{{ __('sanabel.simulator.rank_now') }}</th>
                        <th class="p-2 text-start">{{ __('sanabel.simulator.rank_new') }}</th>
                        <th class="p-2 text-start">{{ __('sanabel.simulator.move') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($shown as $row)
                        <tr class="border-t dark:border-gray-700">
                            <td class="p-2">{{ $row['file_number'] }}</td>
                            <td class="p-2">{{ $row['name'] }}</td>
                            <td class="p-2">{{ number_format($row['score_now'], 2) }}</td>
                            <td class="p-2 font-bold">{{ number_format($row['score_new'], 2) }}</td>
                            <td class="p-2">{{ $row['rank_now'] }}</td>
                            <td class="p-2 font-bold">{{ $row['rank_new'] }}</td>
                            <td @class([
                                'p-2 font-bold',
                                'text-success-600' => $row['move'] > 0,
                                'text-danger-600' => $row['move'] < 0,
                                'text-gray-400' => $row['move'] === 0,
                            ])>
                                {{ $row['move'] > 0 ? '▲ '.$row['move'] : ($row['move'] < 0 ? '▼ '.abs($row['move']) : '—') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
