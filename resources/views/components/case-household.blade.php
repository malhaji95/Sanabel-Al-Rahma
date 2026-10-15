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
        __('sanabel.masked.debt') => isset($case['has_debt'])
            ? ($case['has_debt']
                ? number_format((int) $case['debt_amount']) . ' ' . config('sanabel.currency')
                : __('sanabel.no'))
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

    $conditions = $case['conditions'] ?? [];
    $dependents = $case['dependents'] ?? [];
@endphp

@if ($rows->isNotEmpty() || $owned->isNotEmpty() || $conditions !== [] || $dependents !== [])
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

        @if ($conditions !== [])
            <div class="mt-4">
                <p class="text-sm" style="color: var(--text-muted);">{{ __('sanabel.masked.conditions') }}</p>
                <div class="mt-1.5 flex flex-wrap gap-1.5">
                    @foreach ($conditions as $condition)
                        <span class="badge" style="background-color: var(--page); color: var(--text-muted);">{{ $condition }}</span>
                    @endforeach
                </div>
            </div>
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
                            @if ($dependent['gender_label'])
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

                            @foreach ($dependent['status_labels'] as $status)
                                <span class="badge" style="color: var(--text-muted);">{{ $status }}</span>
                            @endforeach

                            @foreach ($dependent['conditions'] as $condition)
                                <span class="badge bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200">{{ $condition }}</span>
                            @endforeach
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>
@endif
