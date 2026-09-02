{{--
    A published case as a donor sees it. Every value here comes from
    MaskedCaseResource: a file number, an area, bands and labels.
    No name, no address, no exact age, no raw score.
--}}
@props(['case'])

<article class="card flex h-full flex-col justify-between">
    <div>
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="text-xs text-slate-500">{{ __('sanabel.beneficiary.file_number') }}</p>
                <p class="font-bold">{{ $case['file_number'] }}</p>
            </div>

            <span @class([
                'badge',
                'bg-red-100 text-red-800' => $case['urgency_label'] === __('sanabel.masked.urgency.critical'),
                'bg-amber-100 text-amber-800' => $case['urgency_label'] === __('sanabel.masked.urgency.high'),
                'bg-slate-100 text-slate-700' => ! in_array($case['urgency_label'], [
                    __('sanabel.masked.urgency.critical'),
                    __('sanabel.masked.urgency.high'),
                ], true),
            ])>{{ $case['urgency_label'] }}</span>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
            <div>
                <dt class="text-slate-500">{{ __('sanabel.public.area') }}</dt>
                <dd>{{ $case['area_ar'] }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">{{ __('sanabel.public.family_size') }}</dt>
                <dd>{{ $case['family_size'] }}</dd>
            </div>
            <div class="col-span-2">
                <dt class="text-slate-500">{{ __('sanabel.public.composition') }}</dt>
                {{-- Each band stays on one line: a bare digit next to Arabic text
                     breaks badly across a line in a bidirectional layout. --}}
                <dd class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs">
                    @foreach (['child', 'adult', 'elderly'] as $band)
                        <span class="whitespace-nowrap">
                            {{ __('sanabel.masked.age_band.' . $band) }}
                            <span class="font-medium">{{ $case['age_bands'][$band] }}</span>
                        </span>
                    @endforeach
                </dd>
            </div>
            <div>
                <dt class="text-slate-500">{{ __('sanabel.beneficiary.support_type') }}</dt>
                <dd>{{ $case['need_type_label'] }}</dd>
            </div>
        </dl>

        <div class="mt-4">
            <div class="flex items-center justify-between text-sm">
                <span class="text-slate-500">{{ __('sanabel.beneficiary.coverage') }}</span>
                <span class="font-medium">{{ $case['coverage_percent'] }}%</span>
            </div>
            <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100">
                <div class="h-full bg-sanabel-500" style="width: {{ $case['coverage_percent'] }}%"></div>
            </div>
            <p class="mt-2 text-sm">
                {{ __('sanabel.public.remaining') }}:
                <span class="font-medium">{{ number_format($case['remaining_amount']) }}</span>
                {{ $case['currency'] }}
            </p>
        </div>
    </div>

    {{ $slot }}
</article>
