<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;

/*
 | T-148 — one month, one name.
 |
 | Found while fitting out the basket (T-147): the first paint of a page wrote
 | «تشرين الأول» and everything after the donor's first click wrote «أكتوبر».
 |
 | Livewire carries the application's locale in the component's memo and sets
 | it again before the component runs on every update. Setting the application
 | locale moves Carbon with it, so the `ar_SY` calendar chosen in config was
 | replaced by the plain `ar` one halfway through the page's life. The two are
 | separate settings — `ar` for the interface strings, `ar_SY` for the names of
 | the months — and they stay separate.
 */

it('keeps the Syrian month names when the application locale is set again', function () {
    expect(Carbon::getLocale())->toBe(config('sanabel.date_locale'));

    // What Livewire does on every update request.
    app()->setLocale(config('app.locale'));

    expect(Carbon::getLocale())->toBe(config('sanabel.date_locale'))
        ->and(CarbonImmutable::getLocale())->toBe(config('sanabel.date_locale'));
});

it('writes October the way it is written in Syria, before and after', function () {
    $october = Carbon::create(2026, 10, 1);

    expect($october->translatedFormat('F Y'))->toBe('تشرين الأول 2026');

    app()->setLocale(config('app.locale'));

    expect($october->translatedFormat('F Y'))->toBe('تشرين الأول 2026');
});
