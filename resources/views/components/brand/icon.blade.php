{{--
    T-89 — one of the icons shipped under public/icons, drawn inline.

    Inline and not an <img>: every file in that folder is stroked with
    `currentColor`, which an <img> cannot inherit — it would draw black on a
    green card. The name comes from the content card the association chose it
    on, so it is checked against the folder before it is read and a renamed
    file leaves a gap rather than an error.
--}}
@props(['name', 'class' => 'h-6 w-6'])

@php
    $file = public_path('icons/'.basename((string) $name).'.svg');
    $svg = is_file($file) ? file_get_contents($file) : null;

    if ($svg !== null) {
        // The file carries width="24" height="24"; dropping both lets the span
        // size it, so the same icon serves a 24px badge and a 40px tile.
        $svg = preg_replace('/\s(width|height)="\d+"/', '', $svg, 2);

        // T-294 — the files ship with the drawing tool's own <title>heart</title>.
        // The span is aria-hidden, so no screen reader ever announces it, but a
        // browser still shows it as a tooltip when a reader points at the icon,
        // and it lands in the page's text: an English word on an Arabic page.
        // The icon is decoration and carries no name of its own.
        $svg = preg_replace('#<title>.*?</title>#s', '', $svg, 1);
    }
@endphp

@if ($svg)
    <span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center justify-center '.$class]) }} aria-hidden="true">
        {!! $svg !!}
    </span>
@endif
