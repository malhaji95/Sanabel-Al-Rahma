{{--
    A published case as a donor sees it.

    Every value comes from MaskedCaseResource: a file number, an area, bands and
    labels. No name, no address, no exact age, no exact rent, no raw score.
--}}
@props(['case', 'showNote' => null])

@php
    /*
     | T-140 — «المبلغ الشهري هذا لازم يكون اللي احنا نحدده للتمويل … ونسبة
     | التغطية اللي معروض بالشاشة الخارجية حسب التبرع اللي يجييه», 28 October.
     |
     | The donor reads the stage the association is collecting for: its amount,
     | its remainder and its percentage. The whole monthly need is still on the
     | card, in the association's own sentence underneath, because a family at
     | 100% of a 30% stage is short seven tenths of what it needs — and the
     | panel, the ranking and the priority band all keep reading the real need,
     | which is where that distinction decides who is paid first.
     */
    $coveredPercent = $case['coverage_percent'];
    // The badge reads the priority band, which comes from how much of the need
    // is still uncovered. Running out of time is a separate thing, so it gets
    // its own small marker rather than overwriting the band.
    $band = $case['priority_band'];
    $urgent = $case['is_urgent'];

    /*
     | T-78 — the stage being funded now. The badge and the meter stay on the
     | real need: a family at 100% of a 30% target is still short seven tenths
     | of what it needs, and a meter that said otherwise would mislead both the
     | donor and the family.
     */
    $targetPercent = $case['target_percent'] ?? 100;
    $isStaged = $targetPercent > 0 && $targetPercent < 100;

    if ($isStaged) {
        $coveredPercent = $case['target_coverage_percent'] ?? $coveredPercent;
    }
    // Null means «follow the list switch», which is what the resource carries.
    $showNote = ($showNote ?? ($case['show_sufficiency_note'] ?? false)) && $isStaged;

    /*
     | T-83 — the month these figures answer. A donation covers one month and
     | no other, so the card says which; and a month already answered says so
     | rather than showing «المتبقي 0» and leaving the donor to work it out.
     */
    $monthLabel = $case['coverage_month_label'] ?? null;
    $covered = (bool) ($case['is_covered'] ?? false);
@endphp

<article class="card-interactive flex h-full flex-col">
    <header class="flex items-start justify-between gap-3">
        <div>
            <p class="text-xs" style="color: var(--text-muted);">{{ __('sanabel.beneficiary.file_number') }}</p>
            <p class="tabular text-lg font-bold" dir="ltr">{{ $case['file_number'] }}</p>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-1.5">
            @if ($urgent)
                <span class="badge bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M12 2 1 21h22L12 2Zm0 6 1 7h-2l1-7Zm0 9.5a1.2 1.2 0 1 1 0 2.4 1.2 1.2 0 0 1 0-2.4Z"/>
                    </svg>
                    {{ __('sanabel.masked.urgency.critical') }}
                </span>
            @endif

            <span @class([
                'badge',
                'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200' => $band === 'critical',
                'bg-gold-100 text-gold-800 dark:bg-gold-900/50 dark:text-gold-100' => $band === 'middle',
                'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-200' => $band === 'low',
                'bg-brand-50 text-brand-700 dark:bg-brand-900/50 dark:text-brand-100' => $band === 'complete',
            ])>
                {{ $case['priority_label'] }}
            </span>

            {{-- That zakat may be paid on this file. Which of the eight
                 categories it falls under is never published. --}}
            @if ($case['accepts_zakat'] ?? false)
                <span class="badge bg-brand-50 text-brand-700 dark:bg-brand-900/50 dark:text-brand-100">
                    {{ __('sanabel.zakat.accepts') }}
                </span>
            @endif
        </div>
    </header>

    <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
        <div>
            <dt style="color: var(--text-muted);">{{ __('sanabel.public.area') }}</dt>
            <dd class="font-medium">{{ $case['area_ar'] }}</dd>
        </div>
        <div>
            <dt style="color: var(--text-muted);">{{ __('sanabel.public.family_size') }}</dt>
            <dd class="tabular font-medium">{{ $case['family_size'] }}</dd>
        </div>

        <div class="col-span-2">
            <dt style="color: var(--text-muted);">{{ __('sanabel.public.composition') }}</dt>
            {{-- Each band stays on one line: a bare digit beside Arabic text breaks
                 badly across a line in a bidirectional layout. --}}
            <dd class="mt-1.5 flex flex-wrap gap-1.5">
                @foreach (['child', 'adult', 'wife', 'elderly'] as $band)
                    @if ($case['age_bands'][$band] > 0)
                        <span
                            class="badge whitespace-nowrap"
                            style="background-color: var(--page); color: var(--text-muted);"
                        >
                            {{ __('sanabel.masked.age_band.' . $band) }}
                            <span class="tabular font-semibold" style="color: var(--text);">{{ $case['age_bands'][$band] }}</span>
                        </span>
                    @endif
                @endforeach
            </dd>
        </div>

        <div class="col-span-2 flex flex-wrap gap-1.5">
            <span class="badge" style="background-color: var(--page); color: var(--text-muted);">
                {{ $case['need_type_label'] }}
            </span>
            @if ($case['has_chronic_illness'])
                <span class="badge" style="background-color: var(--page); color: var(--text-muted);">
                    {{ __('sanabel.masked.health') }}
                </span>
            @endif
            @if ($case['is_renting'])
                <span class="badge" style="background-color: var(--page); color: var(--text-muted);">
                    {{ $case['rent_band'] }}
                </span>
            @endif
        </div>

        {{-- T-82 — four digits of the household's own account, never more, and
             only while the association's switch is open. The sentence beside it
             is their own condition for showing them at all: the money goes to
             the association's fund, so a donor who reads the digits as somewhere
             to transfer to is being told, in the same breath, that it is not. --}}
        @if (filled($case['wallet_last4'] ?? null))
            <div class="col-span-2">
                <dt style="color: var(--text-muted);">{{ __('sanabel.masked.wallet_last4') }}</dt>
                <dd>
                    <span class="tabular font-medium" dir="ltr">&bull;&bull;&bull;&bull; {{ $case['wallet_last4'] }}</span>
                    <span class="mt-1 block text-xs" style="color: var(--text-muted);">
                        {{ __('sanabel.masked.wallet_last4_note') }}
                    </span>
                </dd>
            </div>
        @endif
    </dl>

    <div class="mt-5">
        {{-- Only while there is something to fund: the completed line below
             names the month itself, and saying it twice reads like a stutter. --}}
        @if ($monthLabel && ! $covered)
            <p class="mb-1.5 text-xs font-medium" style="color: var(--text-muted);">
                {{ __('sanabel.funding.covers_month', ['month' => $monthLabel]) }}
            </p>
        @endif

        {{-- T-139 — «ولما يكتمل كامل المبلغ يتم الكتابة فوق نسبة التغطية»,
             28 October, in the association's own words. Above the percentage,
             because the percentage is where the eye lands. --}}
        @if ($covered || ($isStaged && ($case['target_complete'] ?? false)))
            <div class="mb-2 rounded-xl p-3 text-xs leading-relaxed"
                 style="background-color: color-mix(in srgb, var(--accent) 10%, transparent);">
                <p>{{ __('sanabel.funding.good_news_complete') }}</p>

                <a href="{{ route('cases.browse') }}" class="mt-1.5 inline-block font-semibold underline"
                   style="color: var(--accent);">
                    {{ __('sanabel.funding.good_news_browse') }}
                </a>
            </div>
        @endif

        <div class="flex items-baseline justify-between text-sm">
            <span style="color: var(--text-muted);">{{ __('sanabel.beneficiary.coverage') }}</span>
            <span class="tabular font-semibold">{{ $coveredPercent }}%</span>
        </div>

        <div
            class="meter mt-1.5"
            role="progressbar"
            aria-valuenow="{{ $coveredPercent }}"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-label="{{ __('sanabel.beneficiary.coverage') }}"
        >
            {{-- T-138 — the association's four bands, on the family's bar as
                 on a campaign's: red under forty, amber to eighty, light green
                 to ninety-nine, and the identity's deep green when it is
                 done. --}}
            @if ($coveredPercent > 0)
                <div
                    class="meter-fill meter-fill-{{ \App\Services\ProgressBand::of($coveredPercent) }}"
                    style="width: {{ $coveredPercent }}%"
                ></div>
            @endif
        </div>

        <p class="mt-2.5 text-sm">
            @if ($covered && $monthLabel)
                <span class="font-bold">{{ __('sanabel.funding.month_complete', ['month' => $monthLabel]) }}</span>
            @else
                {{-- The stage's remainder where there is a stage, because that
                     is the figure the appeal is asking for. --}}
                <span style="color: var(--text-muted);">{{ __('sanabel.public.remaining') }}:</span>
                <span class="tabular font-bold">
                    {{ number_format($isStaged ? $case['target_remaining'] : $case['remaining_amount']) }}
                </span>
                <span style="color: var(--text-muted);">{{ \App\Services\Money::label($case['currency']) }}</span>
            @endif
        </p>

        @if ($isStaged && ! $covered)
            <p class="mt-1.5 text-sm">
                <span style="color: var(--text-muted);">{{ __('sanabel.funding.target_amount') }}:</span>
                <span class="tabular font-bold">{{ number_format($case['target_amount']) }}</span>
                <span style="color: var(--text-muted);">{{ \App\Services\Money::label($case['currency']) }}</span>

                @if ($case['target_complete'] ?? false)
                    <span class="badge bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">
                        {{ __('sanabel.funding.target_complete') }}
                    </span>
                @endif
            </p>
        @endif

        {{-- The association's own sentence, behind its own switch. --}}
        @if ($showNote)
            <p class="mt-2 text-xs" style="color: var(--text-muted);">
                {{ __('sanabel.funding.sufficiency_note', [
                    'need' => number_format($case['need_amount']),
                    // T-136 — the words, not the stored code.
                    'currency' => \App\Services\Money::label($case['currency']),
                    'percent' => $targetPercent,
                ]) }}
            </p>
        @endif
    </div>

    <div class="mt-auto pt-4">
        {{ $slot }}
    </div>
</article>
