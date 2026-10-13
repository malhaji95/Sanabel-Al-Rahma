<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 | T-85 — CICD-02.
 |
 | The pipeline before this was the one the Laravel skeleton ships with: it ran
 | phpunit against sqlite on branches named master and *.x, which this project
 | has neither of. So every push ran nothing, and a deploy that hung on a
 | migration lock reached the client before it reached a test.
 |
 | A workflow cannot be run from here, so what is held here is what would
 | silently rot: that the workflow exists and names the three checks, that the
 | file a new developer copies actually works, and that the secret scan bites
 | as well as passes. The last one is the only part of the pipeline that is a
 | script of ours, so it is the only part a test can execute.
 */

it('runs the project suite, the dependency audit and the secret scan on every push', function () {
    $workflow = base_path('.github/workflows/checks.yml');

    expect(File::exists($workflow))->toBeTrue('there is no checks workflow');

    $yaml = File::get($workflow);

    expect($yaml)
        // The project's own suite, not phpunit against sqlite.
        ->toContain('php artisan test')
        // On the engine the application runs on: rule 6 holds reservations
        // with lockForUpdate, and the isolation a race depends on is this one's.
        ->toContain('postgres:16')
        // The front end has to be built or every page test fails.
        ->toContain('npm run build')
        ->toContain('composer audit')
        ->toContain('npm audit')
        ->toContain('scripts/secret-scan.sh');

    // On every push, and not only on branches this repository does not have.
    expect($yaml)->not->toContain('- master');
})->group('CICD-02');

it('leaves no workflow from the framework skeleton behind', function () {
    // Three of the four were laravel's own: issue triage, PR locking, a
    // changelog on release. One of them ran on pull_request_target with write
    // scope, which is a privileged trigger on an untrusted event.
    foreach (['issues.yml', 'pull-requests.yml', 'update-changelog.yml', 'tests.yml'] as $stale) {
        expect(File::exists(base_path('.github/workflows/'.$stale)))
            ->toBeFalse($stale.' is still there');
    }
})->group('CICD-02');

it('keeps the file a new developer copies pointed at the engine the project runs on', function () {
    // The pipeline builds its environment from .env.example, so this is not
    // housekeeping: a stale example breaks the build rather than only the
    // newcomer who follows the README.
    $example = File::get(base_path('.env.example'));

    expect($example)->toContain('DB_CONNECTION=pgsql')
        ->and($example)->toContain('DB_PORT=5432')
        // Nothing filled in, ever. The scan below is what holds that.
        ->and($example)->toContain('APP_KEY=')
        ->and($example)->not->toContain('APP_KEY=base64:');
})->group('CICD-02', 'CICD-03');

it('finds nothing credential-shaped in the repository as it stands', function () {
    $result = Process::path(base_path())->run('scripts/secret-scan.sh');

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
})->group('CICD-02', 'CICD-03');

it('fails the push when a credential is committed, which is the point', function () {
    // A scan that only ever passes is indistinguishable from no scan. So it is
    // pointed at a repository with a key planted in it.
    $planted = base_path('storage/framework/testing/secret-scan-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($planted);

    try {
        Process::path($planted)->run('git init -q .')->throw();

        File::put($planted.'/deploy.env', implode("\n", [
            // A real-looking assignment, a placeholder that must be tolerated,
            // and a key id. The middle line is the false positive that makes
            // a scan useless if it is not handled.
            'DB_PASSWORD=t7Qv2mEraLq8',
            'MAIL_PASSWORD=...',
            'AKIAIOSFODNN7ABCDEFGH',
        ]));

        Process::path($planted)->run('git add -A')->throw();

        $result = Process::path(base_path())->run('scripts/secret-scan.sh '.escapeshellarg($planted));

        expect($result->failed())->toBeTrue('the scan passed a repository with a key in it')
            ->and($result->output())->toContain('DB_PASSWORD')
            ->and($result->output())->toContain('aws key id')
            // The placeholder is not a finding.
            ->and($result->output())->not->toContain('MAIL_PASSWORD');
    } finally {
        File::deleteDirectory($planted);
    }
})->group('CICD-02', 'CICD-03');
