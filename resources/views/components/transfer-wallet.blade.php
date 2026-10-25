{{--
    T-123 — «خيار عرض QR Code الخاص بمحفظة سنابل لدى شام كاش لسهولة عملية
    التبرع», 26 October.

    One destination for every transfer, and now a code to photograph instead
    of a number to copy by hand. The code carries the wallet number and
    nothing else: a donor scans it into their own wallet app, and no name
    and no amount travels with it.

    Drawn by the application itself (T-119), so the number is never handed to
    a code-generating service on the open internet.
--}}
@props(['wallet'])

@php($qr = $wallet ? app(App\Services\QrCode::class)->svg($wallet['wallet'], moduleSize: 4) : null)

<div class="space-y-3">
    <p class="text-sm font-medium">{{ __('sanabel.transfer.single') }}</p>

    <div class="rounded-lg border p-4" style="border-color: var(--border);">
        <p class="text-sm font-semibold" style="color: var(--brand-primary);">
            {{ __('sanabel.transfer.destination') }}
        </p>

        <p class="tabular mt-2 select-all text-lg font-bold" dir="ltr">{{ $wallet['wallet'] }}</p>

        <p class="mt-1 text-xs" style="color: var(--text-muted);">
            {{ __('sanabel.transfer.holder') }}: {{ $wallet['holder'] }}
        </p>

        @if ($qr)
            <figure class="mt-4 flex flex-col items-center gap-2">
                {{-- White behind it on both schemes: a scanner reads dark on light. --}}
                <div class="rounded-xl bg-white p-2" role="img"
                     aria-label="{{ __('sanabel.transfer.qr_alt') }}">
                    {!! $qr !!}
                </div>

                <figcaption class="text-xs" style="color: var(--text-muted);">
                    {{ __('sanabel.transfer.qr_help') }}
                </figcaption>
            </figure>
        @endif
    </div>
</div>
