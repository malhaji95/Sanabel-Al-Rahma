{{--
    T-281 — «مقارنة بين دراستين مع إظهار أسباب اختلاف النتائج», 9 November.

    Both sides are read from their own snapshots, so this says what happened
    on the two days rather than what today's settings would say of them. The
    reason beside each line is not a guess: it is the comparison of the unit,
    the count and the presence, which the snapshots already hold.

    Colours and borders are written out rather than taken from classes: this
    renders inside Filament's own stylesheet, and a utility its build never
    saw draws nothing at all.
--}}
@php
    $money = \App\Services\Money::short();

    $sign = fn (int|float $n) => $n > 0 ? '+'.number_format($n, is_float($n) ? 2 : 0)
        : ($n < 0 ? '−'.number_format(abs($n), is_float($n) ? 2 : 0) : '—');

    $colour = fn (int|float $n) => $n > 0 ? '#166534' : ($n < 0 ? '#9B2C2C' : 'inherit');

    $lineName = function (array $row) {
        return match ($row['kind']) {
            'class' => __('sanabel.person_class.'.$row['key']),
            'adjustment' => \Illuminate\Support\Facades\Lang::has('sanabel.need_breakdown.adjustments.'.$row['key'])
                ? __('sanabel.need_breakdown.adjustments.'.$row['key'])
                : $row['key'],
            'rent' => __('sanabel.rent_reference.amount'),
            default => __('sanabel.need_breakdown.sources.manual'),
        };
    };
@endphp

<div dir="rtl" style="font-size: 0.875rem; line-height: 1.6;">
    @if ($comparison === [])
        <div style="opacity: 0.7;">{{ __('sanabel.study_comparison.none') }}</div>
    @else
        <div style="opacity: 0.8; margin-bottom: 0.75rem;">
            {{ __('sanabel.study_comparison.sides', [
                'older' => $comparison['older']['at']?->format('Y-m-d H:i'),
                'newer' => $comparison['newer']['at']?->format('Y-m-d H:i'),
            ]) }}
        </div>

        {{-- ١ — الأرقام الأربعة والنقطة --}}
        <table style="width: 100%; border-collapse: collapse; text-align: right;">
            <thead>
                <tr style="border-bottom: 1px solid rgba(128,128,128,0.35);">
                    <th style="padding: 0.4rem 0.25rem; font-weight: 600;">{{ __('sanabel.study_comparison.figure') }}</th>
                    <th style="padding: 0.4rem 0.25rem; font-weight: 600; white-space: nowrap;">{{ __('sanabel.study_comparison.older') }}</th>
                    <th style="padding: 0.4rem 0.25rem; font-weight: 600; white-space: nowrap;">{{ __('sanabel.study_comparison.newer') }}</th>
                    <th style="padding: 0.4rem 0.25rem; font-weight: 600; white-space: nowrap;">{{ __('sanabel.study_comparison.delta') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($comparison['totals'] as $row)
                    <tr style="border-bottom: 1px solid rgba(128,128,128,0.18);">
                        <td style="padding: 0.4rem 0.25rem; font-weight: 600;">
                            {{ __('sanabel.study_comparison.figures.'.$row['key']) }}
                        </td>
                        <td style="padding: 0.4rem 0.25rem; white-space: nowrap;">{{ number_format($row['older'], is_float($row['older']) ? 2 : 0) }}</td>
                        <td style="padding: 0.4rem 0.25rem; white-space: nowrap;">{{ number_format($row['newer'], is_float($row['newer']) ? 2 : 0) }}</td>
                        <td style="padding: 0.4rem 0.25rem; white-space: nowrap; font-weight: 700; color: {{ $colour($row['delta']) }};">
                            {{ $sign($row['delta']) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- ٢ — لماذا تحرّك الاحتياج --}}
        <div style="font-weight: 600; margin: 1rem 0 0.4rem;">{{ __('sanabel.study_comparison.need_title') }}</div>

        @if ($comparison['need'] === [])
            <div style="opacity: 0.75;">{{ __('sanabel.study_comparison.need_same') }}</div>
        @else
            <table style="width: 100%; border-collapse: collapse; text-align: right;">
                <tbody>
                    @foreach ($comparison['need'] as $row)
                        <tr style="border-bottom: 1px solid rgba(128,128,128,0.18);">
                            <td style="padding: 0.4rem 0.25rem;">
                                <div style="font-weight: 600;">{{ $lineName($row) }}</div>
                                <div style="opacity: 0.78;">
                                    @foreach ($row['reasons'] as $reason)
                                        {{ __('sanabel.study_comparison.reasons.'.$reason) }}@if (! $loop->last) · @endif
                                    @endforeach
                                </div>
                                @if (in_array('reference_changed', $row['reasons'], true))
                                    <div style="opacity: 0.78;">
                                        {{ number_format($row['older_unit']) }} ← {{ number_format($row['newer_unit']) }}
                                        @if ($row['older_version'] !== $row['newer_version'])
                                            · {{ __('sanabel.need_breakdown.version') }}
                                            {{ $row['older_version'] }} ← {{ $row['newer_version'] }}
                                        @endif
                                    </div>
                                @endif
                                @if (in_array('band_changed', $row['reasons'], true))
                                    <div style="opacity: 0.78;">
                                        {{ __('sanabel.rent_reference.band') }}:
                                        {{ $row['older_band'] }} ← {{ $row['newer_band'] }}
                                    </div>
                                @endif
                                @if (in_array('count_changed', $row['reasons'], true))
                                    <div style="opacity: 0.78;">
                                        {{ __('sanabel.need_breakdown.count') }}:
                                        {{ $row['older_count'] }} ← {{ $row['newer_count'] }}
                                    </div>
                                @endif
                            </td>
                            <td style="padding: 0.4rem 0.25rem; white-space: nowrap; text-align: left;">
                                {{ number_format($row['older_total']) }} ← {{ number_format($row['newer_total']) }}
                            </td>
                            <td style="padding: 0.4rem 0.25rem; white-space: nowrap; text-align: left; font-weight: 700; color: {{ $colour($row['delta']) }};">
                                {{ $sign($row['delta']) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        {{-- ٣ — ولماذا تحرّك الدخل وما وصل --}}
        <div style="font-weight: 600; margin: 1rem 0 0.4rem;">{{ __('sanabel.study_comparison.income_title') }}</div>

        @if ($comparison['income'] === [])
            <div style="opacity: 0.75;">{{ __('sanabel.study_comparison.income_same') }}</div>
        @else
            <table style="width: 100%; border-collapse: collapse; text-align: right;">
                <tbody>
                    @foreach ($comparison['income'] as $row)
                        <tr style="border-bottom: 1px solid rgba(128,128,128,0.18);">
                            <td style="padding: 0.4rem 0.25rem;">
                                {{ $row['key'] === 'external'
                                    ? __('sanabel.need_breakdown.external')
                                    : __('sanabel.need_breakdown.'.$row['key']) }}
                            </td>
                            <td style="padding: 0.4rem 0.25rem; white-space: nowrap; text-align: left;">
                                {{ number_format($row['older']) }} ← {{ number_format($row['newer']) }}
                            </td>
                            <td style="padding: 0.4rem 0.25rem; white-space: nowrap; text-align: left; font-weight: 700; color: {{ $colour($row['delta']) }};">
                                {{ $sign($row['delta']) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        {{-- ٤ — والنقطة: قيمةُ عاملٍ تحرّكت, أو وزنُه --}}
        <div style="font-weight: 600; margin: 1rem 0 0.4rem;">{{ __('sanabel.study_comparison.score_title') }}</div>

        @if ($comparison['factors'] === [])
            <div style="opacity: 0.75;">{{ __('sanabel.study_comparison.score_same') }}</div>
        @else
            <table style="width: 100%; border-collapse: collapse; text-align: right;">
                <tbody>
                    @foreach ($comparison['factors'] as $row)
                        <tr style="border-bottom: 1px solid rgba(128,128,128,0.18);">
                            <td style="padding: 0.4rem 0.25rem;">
                                <div style="font-weight: 600;">{{ __('sanabel.weight.factors.'.$row['key']) }}</div>
                                <div style="opacity: 0.78;">
                                    @foreach ($row['reasons'] as $reason)
                                        {{ __('sanabel.study_comparison.reasons.'.$reason) }}@if (! $loop->last) · @endif
                                    @endforeach
                                </div>
                                <div style="opacity: 0.78;">
                                    {{ __('sanabel.score_breakdown.value') }}:
                                    {{ number_format($row['older_value'], 2) }} ← {{ number_format($row['newer_value'], 2) }}
                                    · {{ __('sanabel.score_breakdown.weight') }}:
                                    {{ $row['older_weight'] }} ← {{ $row['newer_weight'] }}
                                </div>
                            </td>
                            <td style="padding: 0.4rem 0.25rem; white-space: nowrap; text-align: left; font-weight: 700;
                                       color: {{ $colour($row['newer_share'] - $row['older_share']) }};">
                                {{ $sign(round($row['newer_share'] - $row['older_share'], 2)) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <div style="opacity: 0.8; margin-top: 0.75rem;">{{ __('sanabel.study_comparison.frozen_note') }}</div>
    @endif
</div>
