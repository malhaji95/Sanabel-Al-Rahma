{{--
    T-101 — the household as the association asked it be described on
    15 October, on the family's own page rather than on the grid card.

    Every value comes from MaskedCaseResource: counts, bands and labels out of
    a list. A closed switch sends null, and a null row is simply not drawn —
    so the association turning a group off empties it from the page without a
    deploy, and a family with nothing recorded under a group does the same.
--}}
@props(['case'])

@php
    $rows = collect([
        __('sanabel.masked.applicant') => $case['applicant_gender_label'] ?? null,
        __('sanabel.masked.marital_status') => $case['marital_status_label'] ?? null,
        __('sanabel.masked.dependents_count') => isset($case['dependents_count'])
            ? (string) $case['dependents_count']
            : null,
        __('sanabel.masked.smokes') => isset($case['smokes'])
            ? __($case['smokes'] ? 'sanabel.yes' : 'sanabel.no')
            : null,
        __('sanabel.masked.housing_type') => $case['housing_type_label'] ?? null,
        __('sanabel.masked.rooms') => isset($case['rooms']) ? (string) $case['rooms'] : null,
        __('sanabel.masked.bathrooms') => isset($case['bathrooms']) ? (string) $case['bathrooms'] : null,
        __('sanabel.masked.kitchen') => isset($case['has_kitchen'])
            ? __($case['has_kitchen'] ? 'sanabel.yes' : 'sanabel.no')
            : null,
        __('sanabel.masked.home_safety') => $case['home_safety_label'] ?? null,
        __('sanabel.masked.home_services') => $case['home_services_label'] ?? null,
        // T-106 — «قيمة الديون». The row names the value when there is one
        // and answers the plain question when there is not, rather than
        // reading «قيمة الديون: لا».
        (($case['has_debt'] ?? false)
            ? __('sanabel.masked.debt_amount_row')
            : __('sanabel.masked.debt')) => isset($case['has_debt'])
                ? ($case['has_debt']
                    ? number_format((int) $case['debt_amount']) . ' ' . \App\Services\Money::label()
                    : __('sanabel.no'))
                : null,

        /*
         | T-102 — the health answers, asked for on 15 October and the only
         | ones that were drawn solely when there was something to draw. A
         | family with no illness recorded showed no line at all, which reads
         | as a question nobody asked rather than as an answer.
         |
         | `ongoing_treatment` is null exactly when the association has closed
         | the health switch, so it says which of the two silences this is.
         */
        __('sanabel.masked.conditions') => isset($case['ongoing_treatment'])
            ? (($case['conditions'] ?? []) !== []
                ? implode('، ', $case['conditions'])
                : __(($case['has_chronic_illness'] ?? false) ? 'sanabel.yes' : 'sanabel.no'))
            : null,
        __('sanabel.masked.ongoing_treatment') => isset($case['ongoing_treatment'])
            ? __($case['ongoing_treatment'] ? 'sanabel.yes' : 'sanabel.no')
            : null,
    ])->filter(fn ($value) => filled($value));

    // What the household owns. Each line is a yes with the kinds beside it, or
    // a plain no — never the researcher's own description of the thing.
    $owned = collect([
        __('sanabel.masked.vehicle') => [$case['has_vehicle'] ?? null, $case['vehicles'] ?? []],
        __('sanabel.masked.property') => [$case['has_property'] ?? null, $case['property'] ?? []],
    ])
        ->filter(fn ($pair) => $pair[0] !== null)
        ->map(fn ($pair) => $pair[0] && $pair[1] !== []
            ? implode('، ', $pair[1])
            : __($pair[0] ? 'sanabel.yes' : 'sanabel.no'));

    if (($case['has_livestock'] ?? null) !== null) {
        $owned[__('sanabel.masked.livestock')] = $case['has_livestock'] && ($case['livestock'] ?? []) !== []
            ? collect($case['livestock'])
                ->map(fn (array $head) => $head['label'] . ' — ' . __('sanabel.masked.head_count', ['count' => $head['count']]))
                ->join('، ')
            : __($case['has_livestock'] ? 'sanabel.yes' : 'sanabel.no');
    }

    $dependents = $case['dependents'] ?? [];
@endphp

{{-- T-106 — «وضروري شرح». The association's own paragraph about the
     family, above the columns rather than buried under them: it is the thing
     a donor reads first, and the columns are what it is read against. --}}
@if (filled($case['story_ar'] ?? null))
    <section class="card mt-5">
        <h2 class="text-base font-bold">{{ $case['story_title'] }}</h2>
        <p class="mt-3 leading-loose">{{ $case['story_ar'] }}</p>
    </section>
@endif

@if ($rows->isNotEmpty() || $owned->isNotEmpty() || $dependents !== [])
    <section class="card mt-5">
        <h2 class="text-base font-bold">{{ __('sanabel.masked.household') }}</h2>

        @if ($rows->isNotEmpty() || $owned->isNotEmpty())
            <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                @foreach ($rows->merge($owned) as $label => $value)
                    <div class="flex items-baseline justify-between gap-3 border-b pb-1.5" style="border-color: var(--line);">
                        <dt style="color: var(--text-muted);">{{ $label }}</dt>
                        <dd class="text-end font-medium">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        @if ($dependents !== [])
            <div class="mt-5">
                <p class="text-sm font-medium">{{ __('sanabel.masked.dependents') }}</p>

                {{-- A row per dependant: boy or girl, under fifteen, orphaned,
                     studying or working, and any illness. No name and no age in
                     years, which is the whole reason a roster may be published
                     at all. --}}
                <ul class="mt-2 space-y-2">
                    @foreach ($dependents as $dependent)
                        <li class="flex flex-wrap items-center gap-1.5 rounded-lg px-3 py-2 text-sm" style="background-color: var(--page);">
                            @if ($dependent['relation_label'] ?? null)
                                <span class="font-medium">{{ $dependent['relation_label'] }}</span>
                            @elseif ($dependent['gender_label'])
                                <span class="font-medium">{{ $dependent['gender_label'] }}</span>
                            @endif

                            @if ($dependent['is_under_15'])
                                <span class="badge" style="color: var(--text-muted);">{{ __('sanabel.masked.under_15') }}</span>
                            @endif

                            @if ($dependent['is_orphan'])
                                <span class="badge bg-gold-100 text-gold-800 dark:bg-gold-900/50 dark:text-gold-100">
                                    {{ __('sanabel.masked.orphan') }}
                                </span>
                            @endif

                            {{-- T-106 — «صلة القرابة» and «الصفة»، asked for
                                 by name on 16 October, beside the marital
                                 status that was already here. --}}
                            @foreach ([$dependent['marital_label'] ?? null, $dependent['occupation_label'] ?? null] as $label)
                                @if ($label)
                                    <span class="badge" style="color: var(--text-muted);">{{ $label }}</span>
                                @endif
                            @endforeach

                            @if (($dependent['conditions'] ?? []) !== [])
                                @foreach ($dependent['conditions'] as $condition)
                                    <span class="badge bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200">{{ $condition }}</span>
                                @endforeach
                            @elseif ($dependent['has_condition'] ?? false)
                                {{-- There is one; the association has not opened
                                     the switch that names it. --}}
                                <span class="badge bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200">
                                    {{ __('sanabel.masked.has_condition') }}
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>
@endif
