{{--
    T-150 — the health path's banner, approved by the association on 29 October:
    «نعتمد البنر المرفق ليظهر في الصفحة الرئيسية … مع عدم إظهار نسب الخصومات أو
    تفاصيل الاتفاقيات للجمهور، والمحافظة على الهوية البصرية المعتمدة».

    The artwork is the supplied file, unchanged, and its two drawn buttons are
    made into real links rather than redrawn: the overlays are placed in per
    cent of the picture, so they sit exactly on the buttons at every width the
    image is shown at. Nothing of the agreements is written here — no discount,
    no quota, no provider's name. Those live in the panel, behind an account.

    On a phone the drawn buttons are too small to aim at, so the same two links
    are repeated underneath as ordinary buttons.
--}}
@props(['treatment', 'provider'])

@php
    // Measured on the artwork (1600 × 640), as a share of it.
    $row = 'top: 71.25%; height: 11.875%;';
    $providerBox = 'left: 54.5%; width: 21.875%;';
    $treatmentBox = 'left: 78%; width: 18.56%;';
@endphp

<section class="mt-10" aria-labelledby="health-path-banner">
    <h2 id="health-path-banner" class="sr-only">{{ __('sanabel.health_path.apply_title') }}</h2>

    <div class="relative overflow-hidden rounded-2xl">
        <img
            src="{{ asset('images/home/health-path.webp') }}"
            alt="{{ __('sanabel.health_path.banner_alt') }}"
            class="w-full"
            width="1600"
            height="640"
            loading="lazy"
        >

        {{-- Transparent, and named for a reader who cannot see the artwork.
             Hidden below `sm`, where the drawn buttons are a few pixels tall. --}}
        <a
            href="{{ $provider }}"
            class="absolute hidden rounded-xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 sm:block"
            style="{{ $row }} {{ $providerBox }} outline-color: var(--accent);"
            aria-label="{{ __('sanabel.health_path.join_provider') }}"
        ></a>

        <a
            href="{{ $treatment }}"
            class="absolute hidden rounded-xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 sm:block"
            style="{{ $row }} {{ $treatmentBox }} outline-color: var(--brand-gold, var(--accent));"
            aria-label="{{ __('sanabel.health_path.give_treatment') }}"
        ></a>
    </div>

    <div class="mt-3 grid grid-cols-2 gap-2 sm:hidden">
        <a href="{{ $treatment }}" class="btn-primary justify-center text-center no-underline">
            {{ __('sanabel.health_path.give_treatment') }}
        </a>

        <a href="{{ $provider }}" class="btn-secondary justify-center text-center no-underline">
            {{ __('sanabel.health_path.join_provider') }}
        </a>
    </div>
</section>
