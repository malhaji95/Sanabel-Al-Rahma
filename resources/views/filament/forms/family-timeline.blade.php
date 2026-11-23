{{--
    T-313 — «السجلّ الزمني الموحَّد للأسرة», phase two, item 2.

    One list, newest first, of everything that happened to this family: the
    file's own steps, its studies, the visits, the money, the sponsorships and
    the deliveries. It reads; nothing on this page writes.

    Colours and borders are written out rather than taken from classes, as in
    the breakdown views beside it: this renders inside Filament's own
    stylesheet, and a utility its build never saw draws nothing at all.
--}}
@php
    $record = $getRecord();
    $entries = $record ? app(\App\Services\FamilyTimeline::class)->for($record) : [];

    // A colour per kind, so a reader finds the money without reading every
    // line. Failure is the only one that shouts.
    $tint = [
        'file' => '#64748b',
        'assessment' => '#0e7490',
        'visit' => '#7c3aed',
        'money' => '#15803d',
        'money_failed' => '#b91c1c',
        'sponsorship' => '#a16207',
        'delivery' => '#0f766e',
    ];
@endphp

<div style="display:flex; flex-direction:column; gap:0;">
    @forelse ($entries as $entry)
        @php $colour = $tint[$entry['kind']] ?? '#64748b'; @endphp

        <div style="display:flex; gap:0.75rem; align-items:flex-start; padding:0.6rem 0; border-bottom:1px solid rgb(148 163 184 / 0.25);">
            <span style="flex:none; width:0.6rem; height:0.6rem; margin-top:0.45rem; border-radius:9999px; background-color:{{ $colour }};"></span>

            <div style="flex:1 1 auto; min-width:0;">
                <div style="font-weight:600; color:{{ $colour }};">{{ $entry['title'] }}</div>

                @if ($entry['detail'])
                    <div style="font-size:0.875rem; opacity:0.85;">{{ $entry['detail'] }}</div>
                @endif

                @if ($entry['actor'])
                    <div style="font-size:0.8125rem; opacity:0.7;">
                        {{ __('sanabel.timeline.by', ['name' => $entry['actor']]) }}
                    </div>
                @endif
            </div>

            <div style="flex:none; font-size:0.8125rem; opacity:0.7; white-space:nowrap;">
                {{ $entry['at']->format('Y-m-d') }}
            </div>
        </div>
    @empty
        <div style="padding:0.75rem 0; opacity:0.7;">{{ __('sanabel.timeline.empty') }}</div>
    @endforelse
</div>
