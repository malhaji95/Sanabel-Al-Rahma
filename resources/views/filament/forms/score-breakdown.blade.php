{{--
    T-261 — «ما عارفين آلية عملها كيف تم الاحتساب», 8 November.

    The screen showed one number. This shows the seven lines it is the sum of:
    each factor's value out of a hundred, the weight it was scored with, and
    what that multiplication added to the total. Nothing is recomputed — both
    columns were already on the assessment — so a study from August explains
    itself with August's weights however they have been edited since.

    Colours and borders are written out rather than taken from classes: this
    renders inside Filament's own stylesheet, and a utility its build never
    saw draws nothing at all.
--}}
@php
    $assessment = $getRecord()?->currentAssessment();
    $rows = $assessment?->scoreBreakdown() ?? [];
@endphp

<div dir="rtl" style="font-size: 0.875rem; line-height: 1.6;">
    <div style="font-weight: 600; margin-bottom: 0.5rem;">
        {{ __('sanabel.score_breakdown.title') }}
    </div>

    @if ($rows === [])
        <div style="opacity: 0.7;">{{ __('sanabel.score_breakdown.none') }}</div>
    @else
        <table style="width: 100%; border-collapse: collapse; text-align: right;">
            <thead>
                <tr style="border-bottom: 1px solid rgba(128,128,128,0.35);">
                    <th style="padding: 0.4rem 0.25rem; font-weight: 600;">{{ __('sanabel.score_breakdown.factor') }}</th>
                    <th style="padding: 0.4rem 0.25rem; font-weight: 600; white-space: nowrap;">{{ __('sanabel.score_breakdown.value') }}</th>
                    <th style="padding: 0.4rem 0.25rem; font-weight: 600; white-space: nowrap;">{{ __('sanabel.score_breakdown.weight') }}</th>
                    <th style="padding: 0.4rem 0.25rem; font-weight: 600; white-space: nowrap;">{{ __('sanabel.score_breakdown.share') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr style="border-bottom: 1px solid rgba(128,128,128,0.18);">
                        <td style="padding: 0.4rem 0.25rem;">
                            <div style="font-weight: 600;">
                                {{ __('sanabel.weight.factors.'.$row['key']) }}
                                <span style="opacity: 0.55; font-weight: 400;">({{ $row['key'] }})</span>
                            </div>
                            <div style="opacity: 0.75;">{{ __('sanabel.score_breakdown.about.'.$row['key']) }}</div>

                            @if ($row['parts'] !== [])
                                <div style="opacity: 0.75; margin-top: 0.15rem;">
                                    {{ __('sanabel.score_breakdown.parts') }}
                                    @foreach ($row['parts'] as $part)
                                        {{ __('sanabel.weight.factors.'.$part['key']) }}
                                        ({{ rtrim(rtrim(number_format($part['weight'], 2, '.', ''), '0'), '.') }}){{ $loop->last ? '' : '، ' }}
                                    @endforeach
                                    <div>{{ __('sanabel.score_breakdown.parts_note.'.$row['key']) }}</div>
                                </div>
                            @endif
                        </td>
                        <td style="padding: 0.4rem 0.25rem; white-space: nowrap;">{{ number_format($row['value'], 2) }}</td>
                        <td style="padding: 0.4rem 0.25rem; white-space: nowrap;">{{ rtrim(rtrim(number_format($row['weight'], 2, '.', ''), '0'), '.') }}</td>
                        <td style="padding: 0.4rem 0.25rem; white-space: nowrap; font-weight: 600;">{{ number_format($row['share'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" style="padding: 0.5rem 0.25rem; font-weight: 700;">{{ __('sanabel.score_breakdown.total') }}</td>
                    <td style="padding: 0.5rem 0.25rem; font-weight: 700; white-space: nowrap;">{{ number_format($assessment->base_score, 2) }}</td>
                </tr>
            </tfoot>
        </table>

        <div style="opacity: 0.75; margin-top: 0.5rem;">{{ __('sanabel.score_breakdown.formula') }}</div>
    @endif
</div>
