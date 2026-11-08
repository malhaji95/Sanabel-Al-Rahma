@extends('layouts.app')
@section('title', __('sanabel.donation_receipt.heading'))

{{-- A receipt is forwarded. The preview carries the platform's name and
     nothing of the donor, as the gift card's does. --}}
@section('share_title', __('sanabel.donation_receipt.heading'))

@section('content')
    {{--
        T-262 — «عرض للمتبرع ايصال عند التبرع», 8 November, drawn from the
        association's own design of the same day.

        Their eight rows, in their order: the number, the date, the donor, the
        purpose, the amount, the channel, the bank's reference and the status.
        And the code that points back here, so whoever is shown the receipt can
        satisfy themselves it is real.

        The status is read from the donation every time this page is opened,
        never frozen into it: a receipt printed the minute a donor gave says it
        is waiting to be matched, and the same address says «تم الاستلام
        والتحقق» once finance has matched it. A receipt that claimed the money
        was verified before anybody looked would be the one lie this page could
        tell.

        What is never on it: a family, a file number, a wallet, an amount
        allocated to anybody. Rule 2 has no exception for a link that gets
        forwarded.
    --}}
    <article class="mx-auto max-w-xl">
        <div class="overflow-hidden rounded-2xl border"
             style="border-color: var(--line); background: var(--surface);">

            <div class="border-b p-6 text-center" style="border-color: var(--line);">
                <img src="{{ config('brand.logo.full') }}" alt="{{ __('sanabel.app_name') }}"
                     class="mx-auto h-16 w-auto">

                <h1 class="mt-4 inline-block rounded-xl px-5 py-2 text-lg font-bold"
                    style="background: var(--accent); color: #fff;">
                    {{ __('sanabel.donation_receipt.heading') }}
                </h1>

                <div class="mt-4 flex flex-wrap items-center justify-center gap-3 text-sm">
                    <span class="rounded-xl border px-3 py-2" style="border-color: var(--line);">
                        {{ __('sanabel.donation_receipt.number') }}:
                        <span dir="ltr" class="tabular font-bold">{{ $donation->reference_no }}</span>
                    </span>

                    <span class="rounded-xl border px-3 py-2" style="border-color: var(--line);">
                        {{ __('sanabel.donation_receipt.issued_at') }}:
                        <span dir="ltr" class="tabular">{{ $donation->created_at->format('Y-m-d') }}</span>
                    </span>
                </div>
            </div>

            <div class="p-6 text-center">
                <p class="font-bold" style="color: var(--accent);">
                    {{ __('sanabel.donation_receipt.thanks') }}
                </p>
                <p class="mt-2 leading-8 text-sm">{{ __('sanabel.donation_receipt.intro') }}</p>
            </div>

            <div class="px-6 pb-6">
                <table class="w-full overflow-hidden rounded-xl border text-sm"
                       style="border-color: var(--line);">
                    <tbody>
                        @foreach ([
                            'donor' => $donation->donor?->name_ar ?: __('sanabel.donation_receipt.unknown'),
                            // T-263 — the door by name and number where there
                            // is one, since that is what the donor chose.
                            'kind' => $door
                                ? __('sanabel.donation_receipt.kinds.door', [
                                    'door' => $door->title_ar,
                                    'reference' => $door->reference_no,
                                ])
                                : __('sanabel.donation_receipt.kinds.'.$donation->designation),
                            'amount' => number_format($donation->amount).' '.__('sanabel.currency.'.$donation->currency.'.short'),
                            'channel' => __('sanabel.donation_route.'.$donation->route),
                            'transaction' => $donation->transaction_ref ?: __('sanabel.donation_receipt.unknown'),
                            'status' => __('sanabel.donation_receipt.statuses.'.$donation->status),
                        ] as $key => $value)
                            {{-- The rule is drawn on the cells, not on the
                                 row: a tinted cell paints over a row's own
                                 border and the line comes out half-length. --}}
                            <tr>
                                <th class="w-2/5 border-b p-3 text-right font-bold"
                                    style="border-color: var(--line); background: var(--surface-muted, rgba(0,0,0,.03));">
                                    {{ __('sanabel.donation_receipt.'.$key) }}
                                </th>
                                <td @class(['border-b p-3', 'tabular' => in_array($key, ['amount', 'transaction'], true)])
                                    style="border-color: var(--line);"
                                    @if ($key === 'transaction') dir="ltr" @endif>
                                    {{ $value }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="grid grid-cols-3 items-center gap-3 border-t p-4 text-xs"
                 style="border-color: var(--line); background: var(--surface-muted, rgba(0,0,0,.03));">
                <div class="space-y-1" style="color: var(--text-muted);">
                    @if ($legalName !== '')
                        <p class="font-bold" style="color: var(--text);">{{ $legalName }}</p>
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
                    <div class="mx-auto w-20 [&>svg]:h-auto [&>svg]:w-full">{!! $qrSvg !!}</div>
                    <p class="mt-1" style="color: var(--text-muted);">{{ __('sanabel.donation_receipt.verify') }}</p>
                </div>
            </div>

            <p class="px-6 py-4 text-center text-xs leading-6" style="color: var(--text-muted);">
                {{ __('sanabel.donation_receipt.footer') }}
            </p>
        </div>

        <div class="mt-4 text-center print:hidden">
            <button type="button" class="btn-primary" onclick="window.print()">
                {{ __('sanabel.donation_receipt.print') }}
            </button>
        </div>
    </article>
@endsection
