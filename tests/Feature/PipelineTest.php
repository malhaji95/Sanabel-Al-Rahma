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

        /*
         | A real-looking assignment, a placeholder that must be tolerated, and
         | a key id. The middle line is the false positive that makes a scan
         | useless if it is not handled.
         |
         | Split across concatenations on purpose: written whole they are
         | credential-shaped literals in a tracked file, and the scan would
         | report this very file — which it did, the first time this was run.
         */
        File::put($planted.'/deploy.env', implode("\n", [
            'DB_PASSWORD='.'t7Qv2mEraLq8',
            'MAIL_PASSWORD=...',
            'AKIA'.'IOSFODNN7ABCDEFGH',
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

it('keeps every directory the test suites name inside the repository', function () {
    /*
     | The suite could not run on a fresh clone. phpunit.xml declares a `Unit`
     | suite, tests/Unit was empty, and git does not record an empty directory
     | — so a clone had no such path and `php artisan test` stopped at
     | «Test directory not found» with exit code 2, before its first test.
     |
     | It had been that way for as long as there has been a repository. It
     | surfaced the first time anything ran the suite from a clone.
     */
    $xml = simplexml_load_string(File::get(base_path('phpunit.xml')));

    foreach ($xml->testsuites->testsuite as $suite) {
        foreach ($suite->directory as $directory) {
            $path = trim((string) $directory);

            $tracked = Process::path(base_path())->run('git ls-files '.escapeshellarg($path));

            expect(trim($tracked->output()))->not->toBe(
                '',
                $path.' is named by a test suite and nothing in it is tracked, so a clone has no such directory',
            );
        }
    }
})->group('CICD-02');

/*
 | T-94 — CICD-02 and CICD-04, the two halves of «what did we ship».
 |
 | The scan used to read the checkout, which answers «is a credential there
 | now» and not «was one ever pushed» — and the second is the question that
 | matters, because a credential removed in a later commit is still in every
 | clone. And a release could not say what was inside it: the answer was «read
 | the lock files», which nobody does when an advisory lands.
 */

it('reads the whole history, not only the files as they stand', function () {
    $planted = base_path('storage/framework/testing/secret-history-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($planted);

    try {
        Process::path($planted)->run('git init -q .')->throw();
        Process::path($planted)->run('git config user.email scan@example.test')->throw();
        Process::path($planted)->run('git config user.name scan')->throw();

        // Committed, then deleted and committed again — the shape of «we took
        // it out», which left the key in the clone and the scan silent.
        File::put($planted.'/deploy.env', 'AWS_SECRET'.'_ACCESS_KEY=wJalrXUtnFEMIK7MDENGbPxRfiCY');
        Process::path($planted)->run('git add -A')->throw();
        Process::path($planted)->run('git commit -q -m "oops"')->throw();

        File::delete($planted.'/deploy.env');
        Process::path($planted)->run('git add -A')->throw();
        Process::path($planted)->run('git commit -q -m "removed"')->throw();

        // The checkout is clean, and says so.
        $tree = Process::path(base_path())->run('scripts/secret-scan.sh '.escapeshellarg($planted));
        expect($tree->successful())->toBeTrue($tree->output().$tree->errorOutput());

        // The history is not, and that is what the push must fail on.
        $history = Process::path(base_path())
            ->run('scripts/secret-scan.sh --history '.escapeshellarg($planted));

        expect($history->successful())->toBeFalse('the history scan passed a key that is still in the clone')
            ->and($history->output())->toContain('deploy.env');
    } finally {
        File::deleteDirectory($planted);
    }
})->group('CICD-02', 'CICD-03');

it('passes this repository history, and says how much of it it read', function () {
    $result = Process::path(base_path())->run('scripts/secret-scan.sh --history');

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput())
        ->and($result->output())->toContain('blobs of the history');
})->group('CICD-02');

it('clears a historical blob by its content and never by its name', function () {
    $allowed = File::get(base_path('scripts/secret-scan-allowed-blobs.txt'));

    $hashes = collect(explode("\n", $allowed))
        ->map(fn (string $line) => trim($line))
        ->filter(fn (string $line) => $line !== '' && ! str_starts_with($line, '#'));

    foreach ($hashes as $hash) {
        // A content hash, so an entry can never excuse a future credential:
        // one changed character is a different blob with no allowance. A path
        // would be exempt forever, which is how an allow-list becomes a hole.
        expect($hash)->toMatch('/^[0-9a-f]{40}$/', $hash.' is not a blob hash');

        $exists = Process::path(base_path())->run('git cat-file -e '.escapeshellarg($hash));
        expect($exists->successful())->toBeTrue($hash.' is allowed and is not in this repository');
    }

    // And each one carries its reason above it, not a bare line.
    expect(substr_count($allowed, '#'))->toBeGreaterThan($hashes->count());
})->group('CICD-02');

it('writes a bill of materials naming every component of this release', function () {
    $path = base_path('storage/framework/testing/sbom-'.bin2hex(random_bytes(4)).'.json');

    try {
        $this->artisan('sbom', ['--out' => $path, '--dev' => true])->assertSuccessful();

        $document = json_decode(File::get($path), true);

        expect($document['bomFormat'])->toBe('CycloneDX')
            ->and($document['specVersion'])->toBe('1.5')
            ->and($document['metadata']['component']['name'])->toBe('sanabel-al-rahma');

        $components = collect($document['components']);

        // Every component carries the three things an auditor reads: what it
        // is, which version, and the identifier a scanner matches on.
        foreach ($components as $component) {
            expect($component['name'] ?? '')->not->toBeEmpty()
                ->and($component['version'] ?? '')->not->toBeEmpty()
                ->and($component['purl'] ?? '')->toContain('@');
        }

        // Both package managers, and the framework itself by name and version.
        $framework = $components->firstWhere('name', 'laravel/framework');

        expect($components->filter(fn ($c) => str_starts_with($c['purl'], 'pkg:composer/'))->count())
            ->toBeGreaterThan(50)
            ->and($components->filter(fn ($c) => str_starts_with($c['purl'], 'pkg:npm/'))->count())
            ->toBeGreaterThan(0)
            ->and($framework)->not->toBeNull()
            ->and($framework['version'])->toBe(
                collect(json_decode(File::get(base_path('composer.lock')), true)['packages'])
                    ->firstWhere('name', 'laravel/framework')['version']
            );

        // A development dependency is listed and marked as not shipped, so the
        // document cannot be read as «this is what runs in production».
        expect($components->firstWhere('name', 'pestphp/pest')['scope'])->toBe('excluded');
    } finally {
        File::delete($path);
    }
})->group('CICD-04');

it('keeps the bill of materials out of the release it describes', function () {
    // It is written per build and uploaded as an artifact of that build; a
    // copy committed here would be stale by the next merge and would read as
    // the inventory of something that no longer exists.
    $tracked = Process::path(base_path())->run('git ls-files storage/app/sbom sbom.json');

    expect(trim($tracked->output()))->toBe('');

    expect(File::get(base_path('.github/workflows/checks.yml')))
        ->toContain('php artisan sbom')
        ->toContain('upload-artifact');
})->group('CICD-04');
