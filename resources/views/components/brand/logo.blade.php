{{--
    The official logo, used exactly as supplied.

    variant:
      full     — the complete lockup (mark, name, rule, tagline). Home page,
                 headers, and anywhere with room for it.
      wordmark — the name alone, for tight horizontal space.
      symbol   — the mark alone. Favicon and app icons; not decoration.

    The artwork is never recoloured, stretched, rotated, framed or shadowed.
    `height` sets the rendered height and the width follows the artwork's own
    proportions, so the lockup can never be squashed. The wrapper carries the
    clear space the identity guide requires, so nothing sits flush against it.
--}}
@props([
    'variant' => 'full',
    'height' => 64,
    'href' => null,
    'alt' => null,
])

@php
    $src = config("brand.logo.{$variant}") ?? config('brand.logo.full');
    $clearSpace = config('brand.clear_space_ratio');

    $label = $alt ?? match ($variant) {
        'symbol' => __('sanabel.brand.symbol_alt'),
        default => __('sanabel.app_name'),
    };

    $tag = $href ? 'a' : 'span';
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" @endif
    {{ $attributes->class(['inline-flex items-center justify-center']) }}
    style="padding: {{ $clearSpace }}em; font-size: {{ $height }}px;"
>
    <img
        src="{{ $src }}"
        alt="{{ $label }}"
        height="{{ $height }}"
        style="height: {{ $height }}px; width: auto;"
        class="block max-w-full"
        decoding="async"
    >
</{{ $tag }}>
