@extends('layouts.app')
@section('title', __('sanabel.gift.card_heading'))

{{-- The link a recipient opens shows the platform's own name and nothing of
     anybody: no two names in the preview, because a preview is read by
     whoever the message is forwarded to. --}}
@section('share_title', __('sanabel.gift.card_heading'))

@section('content')
    {{--
        T-241 — the card itself. T-251 — the association under it, and the
        one image the donor sends.

        What is on it: the association's own design, the two names, the
        amount only where the giver chose to show it, the association's own
        name and marks, and a code that points back at this page so anybody
        holding the card can satisfy themselves it is real.

        What is never on it: the recipient's number, a family, a file, a
        figure the giver hid, and no personal telephone number of anybody —
        the association's own advice of 4 November and the right one.
    --}}
    <article class="mx-auto max-w-xl">
        <div id="gift-card" class="overflow-hidden rounded-2xl border"
             style="border-color: var(--line); background: var(--surface);">
            @if ($gift->card?->imageUrl())
                <img id="gift-art" src="{{ $gift->card->imageUrl() }}" alt="{{ $gift->card->name_ar }}"
                     crossorigin="anonymous" class="h-auto w-full object-cover">
            @endif

            <div class="space-y-4 p-6 text-center">
                <h1 class="text-xl font-bold" style="color: var(--accent);">
                    {{ __('sanabel.gift.card_heading') }}
                </h1>

                <p class="leading-8">
                    {{ __('sanabel.gift.card_line', [
                        'giver' => $gift->giver_name_ar,
                        'recipient' => $gift->recipient_name_ar,
                    ]) }}
                </p>

                @if ($gift->show_amount)
                    <p class="tabular font-bold">
                        {{ __('sanabel.gift.card_amount', ['amount' => number_format($gift->amount)]) }}
                        {{ __('sanabel.currency.'.$gift->currency.'.short') }}
                    </p>
                @endif

                <p class="text-sm" style="color: var(--muted);">
                    @if ($gift->campaign?->is_published)
                        {{ __('sanabel.gift.card_project', ['project' => $gift->campaign->title_ar]) }}
                    @else
                        {{ __('sanabel.gift.card_general') }}
                    @endif
                </p>

                <p class="text-sm">{{ __('sanabel.gift.card_thanks') }}</p>
            </div>

            {{-- T-251 — the association's strip, in the three parts they drew:
                 their marks in the middle, the formal details on one side and
                 the verification code on the other. Each detail appears only
                 once they have adopted it. --}}
            <div class="grid grid-cols-3 items-center gap-3 border-t p-4 text-xs"
                 style="border-color: var(--line); background: var(--surface-muted, rgba(0,0,0,.03));">
                <div class="space-y-1" style="color: var(--text-muted);">
                    @if ($website !== '')
                        <p>{{ __('sanabel.gift.official_website') }}</p>
                        <p dir="ltr" class="tabular">{{ $website }}</p>
                    @endif
                    @if ($licenceNo !== '')
                        <p>{{ __('sanabel.gift.licence_no') }}</p>
                        <p dir="ltr" class="tabular">{{ $licenceNo }}</p>
                    @endif
                </div>

                <div class="text-center">
                    <img src="{{ config('brand.logo.symbol') }}" alt="" class="mx-auto h-8 w-auto">
                    <p class="mt-1 font-bold" style="color: var(--accent);">{{ __('sanabel.app_name') }}</p>
                    <p style="color: var(--text-muted);">{{ config('brand.tagline_ar') }}</p>
                </div>

                <div class="text-center">
                    {{-- The code carries its own width and height, so the
                         box tells it to fit rather than leaving it at the
                         module size the service drew it at. --}}
                    <div class="mx-auto w-20 [&>svg]:h-auto [&>svg]:w-full">{!! $qrSvg !!}</div>
                    <p class="mt-1" style="color: var(--text-muted);">{{ __('sanabel.gift.scan_to_verify') }}</p>
                </div>
            </div>

            <p class="px-4 pb-4 text-center text-xs" style="color: var(--text-muted);">
                {{ __('sanabel.gift.reference_no') }}:
                <span dir="ltr" class="tabular">{{ $gift->reference_no }}</span>
            </p>
        </div>

        @if ($gift->card?->imageUrl())
            <div class="mt-4 text-center">
                <button type="button" id="gift-download" class="btn-primary">
                    {{ __('sanabel.gift.download') }}
                </button>
                <p class="mt-1 text-xs" style="color: var(--text-muted);">
                    {{ __('sanabel.gift.download_help') }}
                </p>
            </div>

            @php
                $composed = [
                    'art' => $gift->card->imageUrl(),
                    'line' => __('sanabel.gift.card_line', [
                        'giver' => $gift->giver_name_ar,
                        'recipient' => $gift->recipient_name_ar,
                    ]),
                    'amount' => $gift->show_amount
                        ? __('sanabel.gift.card_amount', ['amount' => number_format($gift->amount)])
                            .' '.__('sanabel.currency.'.$gift->currency.'.short')
                        : '',
                    'association' => __('sanabel.app_name'),
                    'tagline' => config('brand.tagline_ar'),
                    'website' => $website,
                    'licence' => $licenceNo !== '' ? __('sanabel.gift.licence_no').' '.$licenceNo : '',
                    'scan' => __('sanabel.gift.scan_to_verify'),
                    'reference' => $gift->reference_no,
                    'file' => $gift->reference_no.'.png',
                    'accent' => config('brand.colors.primary'),
                    'qr' => $qrMatrix,
                    'logo' => asset(config('brand.logo.symbol')),
                ];
            @endphp

            <script type="application/json" id="gift-data">@json($composed)</script>
            <script src="{{ asset('js/gift-card.js') }}" defer></script>
        @endif
    </article>
@endsection
