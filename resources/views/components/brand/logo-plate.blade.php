{{--
    The logo on the page, with nothing behind it.

    It used to carry a light plate on the dark scheme, because the supplied
    artwork's name and tagline are drawn in a green that reads at 1.7:1 on
    that page. The association looked at that white box on 18 October and
    asked for it gone, so the answer moved to where it belonged: a variant of
    the lockup for the dark scheme (config brand.logo.on_dark), chosen inside
    x-brand.logo.

    The component stays because every caller uses it and because the day a
    surface needs a field again — a logo over a photograph, say — this is
    where that would go.
--}}
@props(['variant' => 'full', 'height' => 64, 'largeHeight' => null, 'href' => null])

<span {{ $attributes->class(['inline-flex items-center justify-center']) }}>
    <x-brand.logo :variant="$variant" :height="$height" :large-height="$largeHeight" :href="$href" />
</span>
