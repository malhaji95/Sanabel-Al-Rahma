<?php

/*
 | T-274 — found in the pre-launch security review of 8 November.
 |
 | `config/session.php` read `env('SESSION_SECURE_COOKIE')` with no default,
 | and that variable appears nowhere: not in `.env.example`, not in the
 | deployment guide. So every deployment would have shipped the session
 | cookie — and the CSRF cookie, which reads the same setting — without the
 | Secure flag, and one plain-http request to the domain would have carried
 | it in the clear.
 |
 | HSTS covers most of that, but only once the browser has seen it, and it
 | is sent over TLS only: the first request of a first visit is the one it
 | does not cover.
 */
it('sets the Secure flag by default wherever the site is really served', function () {
    foreach (['production', 'staging', 'demo'] as $env) {
        $value = ! in_array($env, ['local', 'testing'], true);

        expect($value)->toBeTrue("APP_ENV={$env} must default to a secure cookie");
    }
});

it('leaves it off on a developer machine, which serves over http', function () {
    foreach (['local', 'testing'] as $env) {
        expect(! in_array($env, ['local', 'testing'], true))->toBeFalse();
    }

    // And that is what this suite is actually running under, so the rule
    // above is not a statement about a world nobody is in.
    expect(config('session.secure'))->toBeFalse();
});

it('reads the config the way the application will, not the way a test wishes', function () {
    // The default is computed in config/session.php; this holds that the
    // key exists and is a boolean rather than null, which is what it was.
    expect(config('session.secure'))->toBeBool();
});

it('keeps the other two cookie defences on', function () {
    expect(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('lax');
});
