{{--
    The logo on a surface it is approved for.

    The supplied artwork is the colour version, which is unreadable on green or
    on a dark field. In light mode the page background is already the identity's
    light surface, so the logo sits on it directly — no plate, no frame. In dark
    mode it keeps a light field beneath it rather than being recoloured.

    Once the approved white version is supplied and brand.logo.on_dark is set,
    this component can hand the dark scheme that file and drop the field.
--}}
@props(['variant' => 'full', 'height' => 64, 'href' => null])

<span
    {{ $attributes->class(['inline-flex items-center justify-center rounded-xl dark:bg-[--logo-field]']) }}
>
    <x-brand.logo :variant="$variant" :height="$height" :href="$href" />
</span>
