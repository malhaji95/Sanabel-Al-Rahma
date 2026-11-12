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
    // T-278 — the same view serves the family's file, where the record is the
    // household and the study meant is the one in force, and the history
    // screen, where the record is one particular study.
    $record = $getRecord();
    $assessment = $record instanceof \App\Models\Assessment
        ? $record
        : $record?->currentAssessment();
    $breakdown = $assessment?->needBreakdown() ?? [];
    $money = \App\Services\Money::short();

    /*
     | T-298 — «نسبة التمويل المعتمدة» و«المبلغ المستهدف», وهما السادس
     | والسابع من القيم التسع التي طلبت الجمعية إظهارها مستقلّة.
     |
     | وهما الوحيدان في القائمة اللذان لا تحملهما لقطة الدراسة: النسبة
     | إعدادٌ للمنطقة يُقرأ ساعة السؤال, فتتغيّر بتغيّره ولا تتجمّد بالقاعدة ٨
     | كما يتجمّد كلّ ما فوقهما. ولهذا يُقرآن هنا من اليوم, ويُقالان على
     | أنّهما اليوم, ولا يُرسمان إلّا على الدراسة السارية: «هدف اليوم» فوق
     | دراسةٍ من آب رقمٌ لا يعني شيئًا.
     */
    $household = $assessment?->beneficiary;
    // T-300 — the rent the family actually pays, read today, beside the
    // reference the study was computed on.
    $housing = $household?->housing;
    $isInForce = $assessment
        && $household
        && $assessment->getKey() === $household->currentAssessment()?->getKey();
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
                                    @case('addition') {{ __('sanabel.need_addition.kinds.'.$row['key']) }} @break
                                    @default {{ __('sanabel.need_breakdown.sources.manual') }}
                                @endswitch
                            </div>

                            <div style="opacity: 0.75;">
                                {{ __('sanabel.need_breakdown.sources.'.$row['kind']) }}
                                @if ($row['version'] !== null)
                                    · {{ __('sanabel.need_breakdown.version') }} {{ $row['version'] }}
                                @endif
                            </div>

                            @if (($row['stopped'] ?? false) && $row['kind'] === 'adjustment')
                                <div style="font-weight: 600; color: #9B2C2C;">
                                    {{ __('sanabel.weight.stopped_note') }}
                                </div>
                            @endif

                            @if ($row['kind'] === 'addition')
                                <div style="opacity: 0.75;">{{ $row['reason_ar'] }}</div>
                                @if ($row['approved_by'])
                                    <div style="opacity: 0.75;">
                                        {{ __('sanabel.need_addition.approved_by') }}: {{ $row['approved_by'] }}
                                    </div>
                                @endif
                            @endif

                            {{-- T-304 — البند المعتمَد يقول ما غطّته جهةٌ
                                 أخرى منه, وما بقي منه على الجمعية, وأيَّ
                                 الحالات الثلاث هو. والحالة مقروءةٌ من
                                 المبالغ لا مكتوبة. --}}
                            @if (($row['kind'] ?? null) === 'addition' && ($row['covered'] ?? 0) > 0)
                                <div style="opacity: 0.75; margin-top: 0.2rem;">
                                    {{ __('sanabel.need_addition.coverage_states.'.($row['coverage_state'] ?? 'none')) }}
                                    ·
                                    {{ __('sanabel.need_addition.covered') }}:
                                    {{ number_format($row['covered']) }}
                                    @if (($row['covered_by_health_path'] ?? false))
                                        ({{ __('sanabel.need_addition.covered_by_health_path') }})
                                    @endif
                                </div>
                            @endif

                            @if ($row['kind'] === 'rent')
                                <div style="opacity: 0.75;">
                                    {{ ($row['applied'] ?? false)
                                        ? __('sanabel.need_breakdown.rent_applied')
                                        : __('sanabel.need_breakdown.rent_not_applied') }}
                                </div>

                                {{-- T-300 — «إظهار الإيجار الفعلي والمحتسب
                                     والفرق بينهما في تفاصيل الدراسة». الفعليّ
                                     يُقرأ من السكن اليوم, فيُقال إنّه اليوم:
                                     المحتسَب فوقه مجمّدٌ مع الدراسة. --}}
                                @if (($row['applied'] ?? false) && $housing?->isRenting())
                                    @php($paid = $housing->monthlyRent())
                                    <div style="opacity: 0.75; margin-top: 0.2rem;">
                                        {{ __('sanabel.need_breakdown.rent_paid', [
                                            'amount' => number_format($paid),
                                            'period' => __('sanabel.rent_period.'.($housing->rent_period ?: 'monthly')),
                                        ]) }}
                                        @if ($paid !== (int) $row['total'])
                                            <span style="font-weight: 600;">
                                                {{ __('sanabel.need_breakdown.rent_difference', [
                                                    'amount' => number_format(abs($paid - (int) $row['total'])),
                                                    'direction' => $paid > (int) $row['total']
                                                        ? __('sanabel.need_breakdown.rent_above')
                                                        : __('sanabel.need_breakdown.rent_below'),
                                                ]) }}
                                            </span>
                                        @endif
                                    </div>
                                @endif
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

        {{-- ٣ب — نسبة التمويل المعتمدة والمبلغ المستهدف --}}
        @if ($isInForce)
            @php($coverage = app(\App\Services\CoverageService::class))
            @php($percent = $coverage->targetPercent($household))

            <table style="width: 100%; border-collapse: collapse; text-align: right; margin-top: 0.75rem;">
                <tbody>
                    <tr style="border-bottom: 1px solid rgba(128,128,128,0.18);">
                        <td style="padding: 0.4rem 0.25rem;">{{ __('sanabel.need_breakdown.target_percent') }}</td>
                        <td style="padding: 0.4rem 0.25rem; white-space: nowrap; text-align: left; font-weight: 600;">
                            {{ $percent }}%
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 0.4rem 0.25rem;">{{ __('sanabel.need_breakdown.target_amount') }}</td>
                        <td style="padding: 0.4rem 0.25rem; white-space: nowrap; text-align: left; font-weight: 600;">
                            {{ number_format($coverage->targetAmount($household)) }} {{ $money }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <div style="opacity: 0.8; margin-top: 0.4rem;">{{ __('sanabel.need_breakdown.target_note') }}</div>
        @endif

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
