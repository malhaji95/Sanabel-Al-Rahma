<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 | T-70 — the evidence report, and the guards that keep it from rotting.
 |
 | The association's security file forbids the status «تم» without proof, so the
 | report is generated from the tags beside the assertions and from a real run.
 | These tests cover the part the report cannot check about itself: that the
 | catalogue and the tags still agree, and that nothing claims a proof it has
 | lost. They never run the suite — that is the command's job, and a test that
 | ran the suite would run itself.
 */

function catalogue(): array
{
    return require resource_path('evidence/security-catalogue.php');
}

/** Every `->group('ID')` across the test files, as id => [[file, name], …]. */
function evidenceTags(): array
{
    $tags = [];

    foreach (File::allFiles(base_path('tests')) as $file) {
        $description = null;

        foreach (file($file->getPathname()) as $line) {
            if (preg_match("/^(?:it|test)\('(.*?)',/", $line, $m)) {
                $description = str_replace("\\'", "'", $m[1]);
            }

            if ($description !== null && preg_match('/->group\((.*?)\)/', $line, $m)) {
                foreach (explode(',', $m[1]) as $id) {
                    $id = trim($id, " '\"");

                    if ($id !== '') {
                        $tags[$id][] = [Str::after($file->getPathname(), base_path().'/'), $description];
                    }
                }
            }
        }
    }

    return $tags;
}

it('carries the whole security file, not a selection from it', function () {
    $catalogue = catalogue();

    // 58 implementation items and 15 acceptance tests, as the file numbers them.
    expect($catalogue['items'])->toHaveCount(58)
        ->and($catalogue['acceptance'])->toHaveCount(15);

    foreach ($catalogue['items'] as $id => $item) {
        $section = Str::before($id, '-');

        expect($catalogue['sections'])->toHaveKey($section)
            ->and($item['state'])->toBeIn(['done', 'partial', 'pending', 'not_applicable'])
            ->and($item['scope'])->toBeIn(['app', 'hosting', 'client', 'phase2'])
            ->and($item['title'])->not->toBeEmpty()
            ->and($item['priority'])->toBeIn(['P0', 'P1', 'RUN']);
    }
});

it('says what is missing from every item it does not call done', function () {
    foreach (catalogue()['items'] as $id => $item) {
        if ($item['state'] === 'done') {
            continue;
        }

        expect(trim($item['gap'] ?? ''))->not->toBeEmpty(
            "{$id} is not done and does not say what is missing"
        );
    }
});

it('tags nothing that is not an item in the file', function () {
    $items = catalogue()['items'];

    foreach (evidenceTags() as $id => $tests) {
        expect(isset($items[$id]))->toBeTrue(
            "{$id} is tagged in {$tests[0][0]} but is not an item in the security file"
        );
    }
});

it('proves every item it calls done', function () {
    $tags = evidenceTags();

    foreach (catalogue()['items'] as $id => $item) {
        if ($item['state'] !== 'done') {
            continue;
        }

        expect($tags[$id] ?? [])->not->toBeEmpty("{$id} claims to be done with no test tagged for it");
    }
});

it('points every tag at a test that is really there', function () {
    foreach (evidenceTags() as $id => $tests) {
        foreach ($tests as [$file, $name]) {
            $source = File::get(base_path($file));

            // Escaped again on the way back: the source spells an apostrophe
            // \' and the tag now holds it plain.
            $inSource = str_replace("'", "\\'", $name);

            expect(str_contains($source, "'".$inSource."'"))->toBeTrue(
                "{$id} points at \"{$name}\" in {$file}, which no longer exists"
            );
        }
    }
});

it('keeps every acceptance test pointed at items that exist', function () {
    $catalogue = catalogue();

    foreach ($catalogue['acceptance'] as $id => $test) {
        expect($test['covers'])->not->toBeEmpty();

        foreach ($test['covers'] as $item) {
            expect(isset($catalogue['items'][$item]))->toBeTrue(
                "{$id} covers {$item}, which is not in the file"
            );
        }
    }
});

it('names every state, scope and section it prints', function () {
    $catalogue = catalogue();

    foreach (array_keys($catalogue['sections']) as $section) {
        expect(__('evidence.open_app'))->not->toBe('evidence.open_app');

        // Every section has at least one item, or the heading prints empty.
        $items = collect($catalogue['items'])->filter(fn ($i, $id) => Str::before($id, '-') === $section);
        expect($items)->not->toBeEmpty("section {$section} has no items");
    }

    foreach (['done', 'partial', 'pending', 'not_applicable'] as $state) {
        expect(__("evidence.state.{$state}"))->not->toBe("evidence.state.{$state}");
    }

    foreach (['app', 'hosting', 'client', 'phase2'] as $scope) {
        expect(__("evidence.scope.{$scope}"))->not->toBe("evidence.scope.{$scope}")
            ->and(__("evidence.open_{$scope}"))->not->toBe("evidence.open_{$scope}");
    }
});

it('writes a report that names every item and the commit it was built from', function () {
    $out = storage_path('app/evidence/test-report.md');
    File::delete($out);

    $this->artisan('evidence:report', ['--skip-tests' => true, '--out' => $out])
        ->assertSuccessful();

    $report = File::get($out);
    $catalogue = catalogue();

    foreach (array_keys($catalogue['items']) as $id) {
        expect($report)->toContain($id);
    }

    foreach (array_keys($catalogue['acceptance']) as $id) {
        expect($report)->toContain($id);
    }

    // The three things a reviewer needs before reading a line of it.
    expect($report)->toContain(__('evidence.title'))
        ->and($report)->toContain(PHP_VERSION)
        ->and($report)->toContain('./vendor/bin/pest --group=AUTH-02');

    File::delete($out);
});

it('spells a tagged description the way the suite reports it', function () {
    /*
     | The gap this covers. A description holding an apostrophe is written
     | `file\'s` in the source and reported `file's` by the suite, so the tag
     | and the result never met and the item read «غير موجود» — a test that
     | passed, shown in the report as missing. An item whose proof cannot be
     | matched is exactly what this report exists to prevent.
     */
    foreach (evidenceTags() as $id => $tests) {
        foreach ($tests as [$file, $name]) {
            expect($name)->not->toContain('\\',
                "{$id} holds an escape in \"{$name}\"; the suite reports it plain"
            );
        }
    }
});

it('offers a tag per item, so an independent reviewer can check one on its own', function () {
    // The point of the tags: `pest --group=AUTH-02` runs exactly that item's
    // proof. A group that names no test would answer such a reviewer with
    // "no tests found", which reads like a pass.
    $tags = evidenceTags();

    foreach (catalogue()['items'] as $id => $item) {
        if ($item['scope'] !== 'app' || $item['state'] === 'pending') {
            continue;
        }

        expect($tags[$id] ?? [])->not->toBeEmpty(
            "{$id} is ours and under way, yet --group={$id} would find nothing"
        );
    }
});

it('runs the suite against the test database, never the working one', function () {
    /*
     | The accident this prevents, once. The command runs pest as a child
     | process, and a child inherits this process's environment — which carries
     | the whole of .env. phpunit.xml's <env> entries do not override a variable
     | that is already set, so the run went at the working database with
     | RefreshDatabase and emptied it. The command now forces phpunit.xml's own
     | values onto the child, and this holds the pin in place.
     */
    $xml = simplexml_load_string(File::get(base_path('phpunit.xml')));
    $pinned = [];

    foreach ($xml->php->env as $entry) {
        $pinned[(string) $entry['name']] = (string) $entry['value'];
    }

    expect($pinned)->toHaveKey('DB_DATABASE');

    // The working database comes from .env, not from config — inside this test
    // the config already carries the pinned value, which proves nothing.
    if (File::exists(base_path('.env'))
        && preg_match('/^DB_DATABASE=(.*)$/m', File::get(base_path('.env')), $m)) {
        expect($pinned['DB_DATABASE'])->not->toBe(trim($m[1]));
    }

    // And the command forces them rather than hoping they are inherited.
    $source = File::get(app_path('Console/Commands/EvidenceReport.php'));

    expect(str_contains($source, '->env($this->testEnvironment())'))->toBeTrue(
        'the suite is spawned without forcing phpunit.xml onto it'
    );

    foreach (array_keys($pinned) as $name) {
        expect(str_contains($source, 'phpunit.xml'))->toBeTrue(
            "the forced environment is not read from phpunit.xml, so {$name} can drift"
        );
    }
});

it('never reads an acceptance test as done while an item under it is not', function () {
    // The row the association will scan first. A test that covers one finished
    // item and one that does not apply yet is not a pass.
    $out = storage_path('app/evidence/test-acceptance.md');
    File::delete($out);

    $this->artisan('evidence:report', ['--skip-tests' => true, '--out' => $out])->assertSuccessful();

    $report = File::get($out);
    $catalogue = catalogue();

    foreach ($catalogue['acceptance'] as $id => $test) {
        $allDone = collect($test['covers'])
            ->every(fn ($item) => $catalogue['items'][$item]['state'] === 'done');

        if ($allDone) {
            continue;
        }

        preg_match('/^\| '.$id.' \|.*$/m', $report, $row);

        expect($row)->not->toBeEmpty("{$id} has no row in the report")
            ->and($row[0])->not->toContain('| '.__('evidence.state.done').' |');
    }

    File::delete($out);
});

/*
 | T-96 — the association's three instructions of 14 October, each held by a
 | test rather than by a promise.
 |
 |   the bar      no P0 may be left «لم يبدأ» at launch, and any P0 left
 |                «جزئي» must say what is missing, why, and whether the
 |                deferral is real
 |   the proposal every item that waits on the environment carries how, who,
 |                what it costs and how it is tested once installed
 |   afterwards   what is re-run once an environment close to production exists
 */

it('makes every open P0 say whether there is a real reason to defer it', function () {
    $open = collect(catalogue()['items'])->filter(
        fn ($item) => $item['priority'] === 'P0'
            && ! in_array($item['state'], ['done', 'not_applicable'], true)
    );

    expect($open)->not->toBeEmpty('there is nothing open to check; update this test');

    $verdicts = array_keys(__('evidence.defer'));

    foreach ($open as $id => $item) {
        expect($item['defer'] ?? null)->toBeIn($verdicts, "{$id} carries no verdict on deferring it")
            ->and(trim($item['why'] ?? ''))->not->toBeEmpty("{$id} gives no reason");

        // A reason that only repeats the title teaches nobody anything.
        expect(strlen($item['why']))->toBeGreaterThan(80, "{$id} gives a reason too short to be one");
    }
});

it('refuses to pass the launch bar while a P0 has not been started', function () {
    $out = storage_path('app/evidence/test-gate.md');
    File::delete($out);

    $unstarted = collect(catalogue()['items'])
        ->filter(fn ($item) => $item['priority'] === 'P0' && $item['state'] === 'pending');

    // As things stand, eighteen of them. The command must say so rather than
    // let a release be cut on a report that reads tidy.
    $this->artisan('evidence:report', ['--skip-tests' => true, '--gate' => true, '--out' => $out])
        ->assertFailed();

    // And without the flag it still writes the report: the bar is a question
    // asked at release, not a reason to stop producing the evidence.
    $this->artisan('evidence:report', ['--skip-tests' => true, '--out' => $out])
        ->assertSuccessful();

    $report = File::get($out);

    expect($report)->toContain(__('evidence.gate.title'))
        ->and($report)->toContain(__('evidence.gate.bar'))
        ->and($report)->toContain(__('evidence.gate.not_met', ['count' => $unstarted->count()]));

    File::delete($out);
});

it('puts every hosting item in the proposal with its four answers', function () {
    $catalogue = catalogue();
    $plan = $catalogue['hosting_plan'];

    // Every item whose scope is the hosting decision has a plan, so none of
    // them can be left as «in the hosting proposal» pointing at nothing.
    $hosting = collect($catalogue['items'])->filter(fn ($item) => $item['scope'] === 'hosting');

    foreach ($hosting as $id => $item) {
        expect(array_key_exists($id, $plan))->toBeTrue("{$id} waits on hosting and is not in the proposal");
    }

    foreach ($plan as $id => $entry) {
        if ($id === 'note') {
            continue;
        }

        expect(array_key_exists($id, $catalogue['items']))->toBeTrue("the proposal plans {$id}, which is not an item");

        foreach (['how', 'responsible', 'cost', 'verify'] as $field) {
            expect(trim($entry[$field] ?? ''))->not->toBeEmpty("{$id} does not say its {$field}");
        }

        // Who does it is the part that goes wrong in silence, so it names a
        // party and not a job title.
        expect($entry['responsible'])->toMatch('/نحن|المؤسسة|المزوّد/u', "{$id} names nobody responsible");
    }
});

it('writes the proposal and the plan that follows it into the report', function () {
    $out = storage_path('app/evidence/test-hosting.md');
    File::delete($out);

    $this->artisan('evidence:report', ['--skip-tests' => true, '--out' => $out])->assertSuccessful();

    $report = File::get($out);
    $catalogue = catalogue();

    expect($report)->toContain(__('evidence.hosting.title'))
        ->and($report)->toContain(__('evidence.hosting.cost'))
        ->and($report)->toContain(__('evidence.hosting.verify'))
        ->and($report)->toContain(__('evidence.after_hosting.title'));

    // Every step of what happens once the environment exists, in order.
    foreach ($catalogue['after_hosting'] as $step) {
        expect($report)->toContain($step['title']);
    }

    File::delete($out);
});

it('keeps the webhook binding again the day a gateway is connected', function () {
    // The association's own words on 14 October: «لا يسري» stands only while
    // there is no payment provider. An item answered by an absence says when
    // the absence ends, or it reads as a permanent pass.
    $catalogue = catalogue();

    $absent = collect($catalogue['items'])->filter(fn ($item) => $item['state'] === 'not_applicable');

    expect($absent)->not->toBeEmpty();

    foreach ($absent as $id => $item) {
        expect(trim($item['reactivates'] ?? ''))->not->toBeEmpty(
            "{$id} is answered by an absence and does not say when it comes back"
        );
    }

    expect($catalogue['items']['PAY-02']['reactivates'])->toContain('بوابة دفع');
});
