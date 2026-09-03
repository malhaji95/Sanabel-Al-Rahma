<?php

/*
 | Guards the things that made the demo image boot wrong the first time, and
 | the settings a deployment silently gets away with until it matters.
 */

it('defaults the container to Arabic', function () {
    // The interface is Arabic-only (CLAUDE.md §5). Without an explicit locale
    // Laravel falls back to English and renders raw translation keys, which is
    // exactly what the first demo build did.
    $dockerfile = file_get_contents(base_path('Dockerfile'));

    expect($dockerfile)->toContain('APP_LOCALE=ar')
        ->toContain('APP_FALLBACK_LOCALE=ar');
});

it('refuses to boot the container without an application key', function () {
    // APP_KEY encrypts national IDs, phones and wallets and keys the HMAC
    // behind national_id_hash. A generated-per-boot key would make stored rows
    // unreadable and silently break duplicate detection, so a missing one is a
    // hard stop rather than something the entrypoint papers over.
    $entrypoint = file_get_contents(base_path('docker/entrypoint.sh'));

    expect($entrypoint)->toContain('APP_KEY is not set')
        ->toContain('exit 1')
        ->not->toContain('key:generate --force');
});

it('does not ship a stale package manifest in the image', function () {
    // bootstrap/cache/packages.php lists dev packages when built locally, and
    // the container then fails to boot looking for Laravel\Pail.
    expect(file_get_contents(base_path('.dockerignore')))
        ->toContain('bootstrap/cache/*.php');

    expect(file_get_contents(base_path('docker/entrypoint.sh')))
        ->toContain('package:discover');
});

it('runs the queue worker and the scheduler alongside the web server', function () {
    // One free container has to carry all of it. Without the scheduler,
    // expired basket holds are never released and families stay locked.
    $supervisor = file_get_contents(base_path('docker/supervisord.conf'));

    expect($supervisor)->toContain('[program:php-fpm]')
        ->toContain('[program:nginx]')
        ->toContain('[program:queue]')
        ->toContain('[program:scheduler]')
        ->toContain('schedule:work')
        // So `supervisorctl status` works from a shell on the running service.
        ->toContain('[supervisorctl]');
});

it('locks composer to the PHP version the project targets', function () {
    // Resolved on a newer PHP, the lock quietly demands that newer PHP and the
    // application will not boot on the version CLAUDE.md §1 specifies.
    $composer = json_decode(file_get_contents(base_path('composer.json')), true);

    expect($composer['config']['platform']['php'] ?? null)->toBe('8.3.0');

    $lock = json_decode(file_get_contents(base_path('composer.lock')), true);

    expect($lock['platform-overrides']['php'] ?? null)->toBe('8.3.0');

    $tooNew = collect($lock['packages'])
        ->filter(fn (array $p) => str_contains(
            str_replace(' ', '', $p['require']['php'] ?? ''), '>=8.4'
        ))
        ->pluck('name');

    expect($tooNew)->toBeEmpty();
});

it('lets a managed database demand TLS', function () {
    // 'prefer' negotiates TLS but falls back to plaintext without complaining,
    // which is not acceptable across the internet for this data.
    expect(config('database.connections.pgsql.sslmode'))->toBe(env('DB_SSLMODE', 'prefer'));

    expect(file_get_contents(base_path('render.yaml')))
        ->toContain('DB_SSLMODE')
        ->toContain('require');
});

it('keeps the demo blueprint on synthetic data and off Redis', function () {
    $render = file_get_contents(base_path('render.yaml'));

    // Rule 11: generated families only.
    expect($render)->toContain('DEMO_SEED')
        // The free plan has no Redis; nothing uses the Redis facade directly.
        ->toContain('QUEUE_CONNECTION')
        ->toContain('database')
        ->not->toContain('redis');
});
