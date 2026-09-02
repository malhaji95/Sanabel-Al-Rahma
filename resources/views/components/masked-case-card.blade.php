{{--
    A published case as a donor sees it.

    Every value comes from MaskedCaseResource: a file number, an area, bands and
    labels. No name, no address, no exact age, no exact rent, no raw score.
--}}
@props(['case'])

@php
    $covered = $case['coverage_percent'];
    $urgent = $case['urgency_label'] === __('sanabel.masked.urgency.critical');
    $high = $case['urgency_label'] === __('sanabel.masked.urgency.high');
@endphp

<article class="card-interactive flex h-full flex-col">
    <header class="flex items-start justify-between gap-3">
        <div>
            <p class="text-xs" style="color: var(--text-muted);">{{ __('sanabel.beneficiary.file_number') }}</p>
            <p class="tabular text-lg font-bold" dir="ltr">{{ $case['file_number'] }}</p>
        </div>

        <span @class([
            'badge',
            'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200' => $urgent,
            'bg-gold-100 text-gold-800 dark:bg-gold-900/50 dark:text-gold-100' => $high,
            'bg-brand-50 text-brand-700 dark:bg-brand-900/50 dark:text-brand-100' => ! $urgent && ! $high,
        ])>
            @if ($urgent)
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 2 1 21h22L12 2Zm0 6 1 7h-2l1-7Zm0 9.5a1.2 1.2 0 1 1 0 2.4 1.2 1.2 0 0 1 0-2.4Z"/>
                </svg>
            @endif
            {{ $case['urgency_label'] }}
        </span>
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
                @foreach (['child', 'adult', 'elderly'] as $band)
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
    </dl>

    <div class="mt-5">
        <div class="flex items-baseline justify-between text-sm">
            <span style="color: var(--text-muted);">{{ __('sanabel.beneficiary.coverage') }}</span>
            <span class="tabular font-semibold">{{ $covered }}%</span>
        </div>

        <div
            class="meter mt-1.5"
            role="progressbar"
            aria-valuenow="{{ $covered }}"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-label="{{ __('sanabel.beneficiary.coverage') }}"
        >
            @if ($covered > 0)
                <div @class(['meter-fill', 'meter-fill-complete' => $covered >= 100]) style="width: {{ $covered }}%"></div>
            @endif
        </div>

        <p class="mt-2.5 text-sm">
            <span style="color: var(--text-muted);">{{ __('sanabel.public.remaining') }}:</span>
            <span class="tabular font-bold">{{ number_format($case['remaining_amount']) }}</span>
            <span style="color: var(--text-muted);">{{ $case['currency'] }}</span>
        </p>
    </div>

    <div class="mt-auto pt-4">
        {{ $slot }}
    </div>
</article>
