{{--
    T-277 — «شاشة تفصيلية داخل دراسة كل أسرة توضح كيف وصل النظام إلى مبلغ
    الاحتياج والعجز والأولوية والتمويل, حتى يستطيع المسؤول مراجعة النتيجة دون
    الرجوع للمبرمج», 9 November.

    T-261 drew the score. This draws the money, and it reads the same way:
    every figure comes out of the study's own snapshot, so one from August
    explains itself with August's reference values however they have been
    edited since. Nothing is recomputed here.

    Colours and borders are written out rather than taken from classes: this
    renders inside Filament's own stylesheet, and a utility its build never
    saw draws nothing at all.
--}}
@php
    $assessment = $getRecord()?->currentAssessment();
    $breakdown = $assessment?->needBreakdown() ?? [];
    $money = \App\Services\Money::short();
@endphp

<div dir="rtl" style="font-size: 0.875rem; line-height: 1.6;">
    <div style="font-weight: 600; margin-bottom: 0.5rem;">
        {{ __('sanabel.need_breakdown.title') }}
    </div>

    @if ($breakdown === [])
        <div style="opacity: 0.7;">{{ __('sanabel.need_breakdown.none') }}</div>
    @else
        @php($t = $breakdown['totals'])

        {{-- ١ — ما يتكوّن منه الاحتياج --}}
        <table style="width: 100%; border-collapse: collapse; text-align: right;">
            <thead>
                <tr style="border-bottom: 1px solid rgba(128,128,128,0.35);">
                    <th style="padding: 0.4rem 0.25rem; font-weight: 600;">{{ __('sanabel.need_breakdown.item') }}</th>
                    <th style="padding: 0.4rem 0.25rem; font-weight: 600; white-space: nowrap;">{{ __('sanabel.need_breakdown.unit') }}</th>
                    <th style="padding: 0.4rem 0.25rem; font-weight: 600; white-space: nowrap;">{{ __('sanabel.need_breakdown.count') }}</th>
                    <th style="padding: 0.4rem 0.25rem; font-weight: 600; white-space: nowrap;">{{ __('sanabel.need_breakdown.amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($breakdown['need'] as $row)
                    <tr style="border-bottom: 1px solid rgba(128,128,128,0.18);">
                        <td style="padding: 0.4rem 0.25rem;">
                            <div style="font-weight: 600;">
                                @switch($row['kind'])
                                    @case('class') {{ __('sanabel.person_class.'.$row['key']) }} @break
                                    @case('adjustment')
                                        {{ \Illuminate\Support\Facades\Lang::has('sanabel.need_breakdown.adjustments.'.$row['key'])
                                            ? __('sanabel.need_breakdown.adjustments.'.$row['key'])
                                            : $row['key'] }}
                                    @break
                                    @case('rent') {{ __('sanabel.rent_reference.amount') }} @break
                                    @default {{ __('sanabel.need_breakdown.sources.manual') }}
                                @endswitch
                            </div>

                            <div style="opacity: 0.75;">
                                {{ __('sanabel.need_breakdown.sources.'.$row['kind']) }}
                                @if ($row['version'] !== null)
                                    · {{ __('sanabel.need_breakdown.version') }} {{ $row['version'] }}
                                @endif
                            </div>

                            @if ($row['kind'] === 'rent')
                                <div style="opacity: 0.75;">
                                    {{ ($row['applied'] ?? false)
                                        ? __('sanabel.need_breakdown.rent_applied')
                                        : __('sanabel.need_breakdown.rent_not_applied') }}
                                </div>
                            @endif
                        </td>
                        <td style="padding: 0.4rem 0.25rem; white-space: nowrap;">{{ number_format($row['unit']) }}</td>
                        <td style="padding: 0.4rem 0.25rem; white-space: nowrap;">{{ $row['count'] > 1 ? $row['count'] : '—' }}</td>
                        <td style="padding: 0.4rem 0.25rem; white-space: nowrap; font-weight: 600;">{{ number_format($row['total']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" style="padding: 0.5rem 0.25rem; font-weight: 700;">{{ __('sanabel.need_breakdown.monthly_need') }}</td>
                    <td style="padding: 0.5rem 0.25rem; font-weight: 700; white-space: nowrap;">
                        {{ number_format($t['monthly_need']) }} {{ $money }}
                    </td>
                </tr>
            </tfoot>
        </table>

        {{-- ٢ — ما يُخصم منه --}}
        <div style="font-weight: 600; margin: 1rem 0 0.4rem;">{{ __('sanabel.need_breakdown.income_title') }}</div>

        <table style="width: 100%; border-collapse: collapse; text-align: right;">
            <tbody>
                @foreach ($breakdown['income'] as $row)
                    <tr style="border-bottom: 1px solid rgba(128,128,128,0.18);">
                        <td style="padding: 0.4rem 0.25rem;">{{ __('sanabel.need_breakdown.'.$row['key']) }}</td>
                        <td style="padding: 0.4rem 0.25rem; white-space: nowrap; text-align: left; font-weight: 600;">
                            {{ number_format($row['total']) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td style="padding: 0.5rem 0.25rem; font-weight: 700;">{{ __('sanabel.need_breakdown.stable_income') }}</td>
                    <td style="padding: 0.5rem 0.25rem; font-weight: 700; white-space: nowrap; text-align: left;">
                        {{ number_format($t['stable_income']) }} {{ $money }}
                    </td>
                </tr>
            </tfoot>
        </table>

        {{-- ٣ — العجز, وهو ما تُحسب عليه النسبة --}}
        <div style="margin-top: 1rem; padding: 0.6rem 0.75rem; border-radius: 0.5rem;
                    background: rgba(31,107,67,0.08);">
            <div style="font-weight: 700;">
                {{ __('sanabel.need_breakdown.deficit') }}:
                {{ number_format($t['monthly_need']) }} − {{ number_format($t['stable_income']) }}
                = {{ number_format($t['deficit']) }} {{ $money }}
            </div>
            <div style="opacity: 0.8; margin-top: 0.2rem;">{{ __('sanabel.need_breakdown.deficit_note') }}</div>
        </div>

        {{-- ٤ — ما وصل عن هذا الشهر --}}
        <div style="font-weight: 600; margin: 1rem 0 0.4rem;">{{ __('sanabel.need_breakdown.received_title') }}</div>

        <table style="width: 100%; border-collapse: collapse; text-align: right;">
            <tbody>
                <tr style="border-bottom: 1px solid rgba(128,128,128,0.18);">
                    <td style="padding: 0.4rem 0.25rem;">{{ __('sanabel.need_breakdown.external') }}</td>
                    <td style="padding: 0.4rem 0.25rem; white-space: nowrap; text-align: left; font-weight: 600;">
                        {{ number_format($breakdown['external']) }}
                    </td>
                </tr>
                <tr>
                    <td style="padding: 0.5rem 0.25rem; font-weight: 700;">{{ __('sanabel.need_breakdown.gap') }}</td>
                    <td style="padding: 0.5rem 0.25rem; font-weight: 700; white-space: nowrap; text-align: left;">
                        {{ number_format($t['gap']) }} {{ $money }}
                    </td>
                </tr>
            </tbody>
        </table>

        <div style="opacity: 0.8; margin-top: 0.4rem;">{{ __('sanabel.need_breakdown.gap_note') }}</div>

        <div style="opacity: 0.75; margin-top: 0.75rem;">
            @if ($breakdown['computed_at'])
                {{ __('sanabel.need_breakdown.computed_at') }}:
                <span dir="ltr" class="tabular">{{ \Illuminate\Support\Carbon::parse($breakdown['computed_at'])->format('Y-m-d H:i') }}</span>
                ·
            @endif
            {{ __('sanabel.need_breakdown.frozen_note') }}
        </div>
    @endif
</div>
