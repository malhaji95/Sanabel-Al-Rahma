{{--
 | T-92 — the head every training document shares: the embedded fonts, the
 | palette and the print rules.
 |
 | It was the top of guide.blade.php until the overview document needed the
 | same 150 lines of it. Expects $documentTitle.
--}}
@php
    $font = function (int $weight) {
        $arabic = public_path("fonts/ibm-plex-sans-arabic-arabic-{$weight}.woff2");

        return file_exists($arabic) ? base64_encode(file_get_contents($arabic)) : null;
    };
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>{{ $documentTitle }}</title>
<style>
@foreach ([400, 500, 600, 700] as $weight)
@if ($data = $font($weight))
@font-face {
    font-family: 'Plex';
    font-weight: {{ $weight }};
    font-style: normal;
    src: url(data:font/woff2;base64,{{ $data }}) format('woff2');
}
@endif
@endforeach

:root {
    --brand: {{ config('brand.colors.primary') }};
    --gold: {{ config('brand.colors.gold') }};
    --ink: {{ config('brand.colors.ink') }};
    --surface: {{ config('brand.colors.surface') }};
    /* Derived, not part of the identity palette: a muted text and a hairline. */
    --muted: color-mix(in srgb, var(--ink) 65%, #fff);
    --line: color-mix(in srgb, var(--brand) 14%, #fff);
    --refused: #B3261E;
}

* { box-sizing: border-box; }

body {
    font-family: 'Plex', system-ui, sans-serif;
    color: var(--ink);
    background: #fff;
    margin: 0;
    padding: 0 28px 48px;
    line-height: 1.75;
    font-size: 15px;
}

header {
    border-bottom: 3px solid var(--brand);
    padding: 28px 0 18px;
    margin-bottom: 28px;
}

header img { height: 46px; }

h1 { font-size: 28px; margin: 14px 0 4px; font-weight: 700; }
h2 { font-size: 20px; margin: 0 0 6px; font-weight: 600; color: var(--brand); }

.eyebrow { color: var(--muted); font-size: 13px; letter-spacing: .02em; }

.summary {
    background: var(--surface);
    border-right: 4px solid var(--gold);
    padding: 16px 18px;
    border-radius: 10px;
    margin-bottom: 10px;
}

.account {
    font-size: 13px;
    color: var(--muted);
    margin: 0 0 26px;
}

.account code { font-family: 'Plex', monospace; direction: ltr; display: inline-block; }

.contents { margin: 0 0 30px; padding: 0 18px 0 0; }
.contents li { margin: 2px 0; }

.step { page-break-inside: avoid; margin-bottom: 38px; }

.step-head { display: flex; align-items: baseline; gap: 10px; margin-bottom: 10px; }

.number {
    background: var(--brand);
    color: #fff;
    border-radius: 999px;
    min-width: 30px;
    height: 30px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
    flex: none;
}

.path {
    font-size: 12px;
    color: var(--muted);
    direction: ltr;
    text-align: left;
    margin: 0 0 10px;
}

figure { margin: 0 0 14px; }

figure img {
    width: 100%;
    border: 1px solid var(--line);
    border-radius: 10px;
    display: block;
}

.missing {
    border: 1px dashed var(--line);
    border-radius: 10px;
    padding: 22px;
    text-align: center;
    color: var(--muted);
    font-size: 13px;
}

.note { margin: 0 0 10px; }
.note strong { display: block; font-size: 13px; color: var(--brand); font-weight: 600; }

.note.refused strong { color: var(--refused); }
.note.next strong { color: var(--gold); }

footer {
    border-top: 1px solid var(--line);
    margin-top: 34px;
    padding-top: 14px;
    color: var(--muted);
    font-size: 12px;
}

@media print {
    body { padding: 0 6px 0; font-size: 12.5px; }
    .step { margin-bottom: 26px; }
}
/*
 | T-92 — the pages that are not a screenshot: the permissions annex at the
 | end of a guide, the matrix sheet, and the overview document. Each starts on
 | a fresh page so a guide can be printed and handed out section by section.
 */
.sheet { page-break-before: always; padding-top: 6px; }

h3 { font-size: 17px; margin: 24px 0 8px; font-weight: 600; color: var(--brand); }

.lede { color: var(--muted); font-size: 13.5px; margin: 0 0 16px; }

table.grid {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    margin: 0 0 16px;
}

table.grid th,
table.grid td {
    border: 1px solid var(--line);
    padding: 5px 8px;
    text-align: start;
    vertical-align: top;
}

table.grid thead th {
    background: var(--surface);
    font-weight: 600;
    font-size: 12.5px;
}

table.grid td.scope { white-space: nowrap; color: var(--brand); font-weight: 600; }

/*
 | The whole matrix: every role across the page. Two things make it readable
 | rather than merely present — the sheet turns on its side, and the scope is
 | a mark with a legend instead of a word that would wrap three times in a
 | column 25px wide.
 */
@page matrix { size: A4 landscape; }
.sheet.wide { page: matrix; }

table.matrix { font-size: 9px; table-layout: fixed; }
table.matrix th, table.matrix td { padding: 3px 2px; text-align: center; }
table.matrix thead th { font-size: 8.5px; line-height: 1.25; vertical-align: bottom; }
table.matrix thead th:first-child { width: 128px; text-align: start; }
table.matrix tbody th { text-align: start; font-weight: 600; font-size: 9px; }
table.matrix td.on { color: var(--brand); font-weight: 700; font-size: 11px; }
table.matrix td.off { color: var(--line); }
table.matrix .readonly { display: block; font-weight: 400; color: var(--muted); }

.legend { font-size: 11px; color: var(--muted); margin: 0 0 12px; }
.legend b { color: var(--brand); font-size: 12px; }

.chips { margin: 0 0 16px; padding: 0; list-style: none; font-size: 12.5px; color: var(--muted); }
.chips li {
    display: inline-block;
    border: 1px solid var(--line);
    border-radius: 999px;
    padding: 2px 9px;
    margin: 0 0 5px 5px;
}

.callout {
    background: var(--surface);
    border-right: 4px solid var(--brand);
    border-radius: 10px;
    padding: 12px 14px;
    margin: 0 0 16px;
    font-size: 13.5px;
}

.journey { margin: 0 0 18px; padding: 0 18px 0 0; }
.journey li { margin: 4px 0; }

.def { page-break-inside: avoid; margin: 0 0 12px; }
.def dt { font-weight: 600; color: var(--brand); font-size: 13.5px; }
.def dd { margin: 2px 0 0; }

.qa { page-break-inside: avoid; margin: 0 0 14px; }
.qa p { margin: 0; }
.qa .q { font-weight: 600; }
.qa .a { color: var(--muted); }

@media print {
    .sheet { padding-top: 0; }
}

</style>
