<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/*
 | The association's security file says the status «تم» may not be accepted
 | without proof. So this builds the proof, from the two places that cannot lie
 | about it: the `->group('AUTH-02')` tag beside each assertion, and the result
 | of actually running the suite. Nothing here is typed by hand, which is the
 | point — a document written once goes stale, and a stale security claim is
 | worse than an honest gap.
 */
class EvidenceReport extends Command
{
    protected $signature = 'evidence:report
        {--out= : Write the report here instead of storage/app/evidence}
        {--skip-tests : Build from the tags alone, without running the suite}
        {--pdf : Also print the report, for handing to the association}
        {--gate : Fail unless the launch bar is met: no P0 left unstarted}';

    protected $description = 'Map every security-file item to the tests that prove it';

    private const CATALOGUE = 'evidence/security-catalogue.php';

    public function handle(): int
    {
        $catalogue = require resource_path(self::CATALOGUE);

        $tags = $this->readTags();
        $results = $this->option('skip-tests') ? null : $this->runSuite();

        $problems = $this->check($catalogue, $tags, $results);

        $path = $this->option('out') ?: storage_path('app/evidence/'.now()->toDateString().'-evidence.md');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->render($catalogue, $tags, $results));

        $this->info(__('evidence.cli.written', ['path' => $path]));

        if ($this->option('pdf')) {
            $this->print($path);
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        if ($problems === []) {
            $this->info(__('evidence.cli.consistent'));
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }

    /*
     | T-77 — the same report as a page, and printed.
     |
     | Markdown belongs beside the code; a board is handed a PDF. So the
     | Markdown is rendered, wrapped in the identity with the typeface embedded,
     | and printed by the same Chromium the training tour uses. The Markdown
     | stays the source: there is one report, in two shapes.
     */
    private function print(string $path): void
    {
        $html = str_replace('.md', '.html', $path);
        $pdf = str_replace('.md', '.pdf', $path);

        File::put($html, view('evidence.report', [
            'body' => Str::markdown(File::get($path), ['html_input' => 'allow']),
        ])->render());

        $result = Process::timeout(600)->run([
            'node', base_path('training/capture.mjs'), 'pdf', $html, $pdf,
        ]);

        if (! $result->successful()) {
            $this->error(__('evidence.cli.print_failed'));
            $this->line($result->errorOutput());

            return;
        }

        $this->info(__('evidence.cli.printed', ['path' => $pdf]));
    }

    /*
     | Every `->group('ID')` in the test files, as item id => list of tests.
     | The tag sits on the line that closes the test, so the description we are
     | still holding when we meet it is the right one.
     */
    private function readTags(): array
    {
        $tags = [];

        foreach (File::allFiles(base_path('tests')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = Str::after($file->getPathname(), base_path().'/');
            $description = null;

            foreach (file($file->getPathname()) as $line) {
                if (preg_match("/^(?:it|test)\('(.*?)',/", $line, $m)) {
                    // A description holding an apostrophe is escaped in the
                    // source and plain in the JUnit log, so it is unescaped
                    // here or the two never meet and the item reads as proved
                    // by a test that does not exist.
                    $description = str_replace("\\'", "'", $m[1]);
                }

                if ($description !== null && preg_match('/->group\((.*?)\)/', $line, $m)) {
                    foreach (preg_split("/\s*,\s*/", trim($m[1])) as $id) {
                        $id = trim($id, " '\"");

                        if ($id !== '') {
                            $tags[$id][] = ['file' => $relative, 'name' => $description];
                        }
                    }
                }
            }
        }

        return $tags;
    }

    /** Runs the whole suite once and reads the JUnit log it writes. */
    private function runSuite(): array
    {
        $this->info(__('evidence.cli.running'));

        $log = storage_path('app/evidence/junit.xml');
        File::ensureDirectoryExists(dirname($log));

        $started = microtime(true);
        Process::timeout(3600)->env($this->testEnvironment())->run(['./vendor/bin/pest', '--log-junit', $log]);
        $seconds = round(microtime(true) - $started);

        $tests = [];
        $totals = ['total' => 0, 'passed' => 0, 'failed' => 0, 'skipped' => 0, 'assertions' => 0, 'seconds' => $seconds];

        // One file nests the cases directly; the whole suite nests a suite per
        // file inside the root one, and a test with a dataset nests a suite of
        // its own under that. Walk it rather than assume a depth.
        $this->collect(simplexml_load_string(File::get($log)), $tests, $totals);

        File::delete($log);

        return ['tests' => $tests, 'totals' => $totals];
    }

    /*
     | The suite has to see what it would see from a fresh shell, and a child of
     | this command does not: this process holds the whole of .env in its
     | environment, and phpunit.xml's <env> entries do not override a variable
     | that is already set. The first run of this command therefore went at the
     | working database with RefreshDatabase and emptied it, and a later one
     | quietly ran forty tests fewer under the wrong settings.
     |
     | So the child gets .env taken back out of its environment — it reads the
     | file itself, as a fresh shell would — and phpunit.xml's own values put in
     | explicitly, which is the one thing a fresh shell cannot get wrong.
     */
    private function testEnvironment(): array
    {
        $env = [];

        if (File::exists(base_path('.env'))) {
            preg_match_all('/^\s*([A-Z0-9_]+)\s*=/m', File::get(base_path('.env')), $matches);

            foreach ($matches[1] as $name) {
                $env[$name] = false;
            }
        }

        $xml = simplexml_load_string(File::get(base_path('phpunit.xml')));

        foreach ($xml->php->env as $entry) {
            $env[(string) $entry['name']] = (string) $entry['value'];
        }

        if (($env['DB_DATABASE'] ?? null) === config('database.connections.'.config('database.default').'.database')) {
            throw new \RuntimeException('phpunit.xml names the working database; refusing to run the suite against it.');
        }

        return $env;
    }

    private function collect(\SimpleXMLElement $node, array &$tests, array &$totals, string $file = ''): void
    {
        foreach ($node->testsuite as $suite) {
            // A dataset's own suite carries no file of its own; it belongs to
            // the one above it.
            $in = (string) $suite['file'] ?: $file;

            foreach ($suite->testcase as $case) {
                $status = isset($case->failure) || isset($case->error) ? 'failed'
                    : (isset($case->skipped) ? 'skipped' : 'passed');

                // JUnit prints the description with its `it`/`test` keyword in
                // front, and a row of a dataset with the row appended. The tags
                // hold the description alone, so every row of one test answers
                // under it, and one failing row fails the test.
                $name = preg_replace(
                    ['/^(?:it|test) /', '/ with data set .*$/'],
                    '',
                    (string) $case['name']
                );

                $key = $in.'::'.$name;

                if (($tests[$key]['status'] ?? null) !== 'failed') {
                    $tests[$key] = ['status' => $status, 'assertions' => (int) $case['assertions']];
                }

                $totals['total']++;
                $totals[$status]++;
                $totals['assertions'] += (int) $case['assertions'];
            }

            $this->collect($suite, $tests, $totals, $in);
        }
    }

    /*
     | The guard that keeps the report honest. A tag naming an item the file
     | does not contain is a typo; an item claiming «تم» with no passing test
     | is the thing the association asked us to make impossible.
     */
    private function check(array $catalogue, array $tags, ?array $results): array
    {
        $problems = [];

        foreach ($tags as $id => $tests) {
            if (! isset($catalogue['items'][$id])) {
                $problems[] = __('evidence.cli.unknown_group', ['id' => $id, 'file' => $tests[0]['file']]);
            }
        }

        foreach ($catalogue['items'] as $id => $item) {
            if ($item['state'] !== 'done') {
                continue;
            }

            $proofs = collect($tags[$id] ?? [])->filter(
                fn ($test) => in_array($this->statusOf($test, $results), ['passed', 'untested'], true)
            );

            if ($proofs->isEmpty()) {
                $problems[] = __('evidence.cli.claimed_without_proof', ['id' => $id]);
            }
        }

        if ($results !== null && $results['totals']['failed'] > 0) {
            $problems[] = __('evidence.cli.failed_tests', ['count' => $results['totals']['failed']]);
        }

        if ($results !== null && $results['totals']['total'] === 0) {
            $problems[] = __('evidence.cli.no_run');
        }

        /*
         | T-96 — the association's bar of 14 October: a P0 that is not done
         | must say what is missing, why, and whether deferring it is
         | justified. An item left open without that sentence is the thing
         | this report exists to make impossible, so it fails the command
         | exactly as a «تم» without a test does.
         */
        foreach ($this->openP0($catalogue) as $id => $item) {
            if (empty($item['defer']) || empty($item['why'])) {
                $problems[] = __('evidence.cli.open_without_reason', ['id' => $id]);
            }
        }

        // And with --gate, the bar itself: nothing may be left unstarted.
        if ($this->option('gate')) {
            $unstarted = $this->openP0($catalogue)->where('state', 'pending');

            if ($unstarted->isNotEmpty()) {
                $problems[] = __('evidence.cli.gate_failed', [
                    'count' => $unstarted->count(),
                    'items' => $unstarted->keys()->implode('، '),
                ]);
            }
        }

        return $problems;
    }

    /** @return Collection<string, array> every P0 that is neither done nor out of scope */
    private function openP0(array $catalogue)
    {
        return collect($catalogue['items'])->filter(
            fn ($item) => $item['priority'] === 'P0'
                && ! in_array($item['state'], ['done', 'not_applicable'], true)
        );
    }

    /** Without a run we can only say the test exists, not that it passed. */
    private function statusOf(array $test, ?array $results): string
    {
        if ($results === null) {
            return 'untested';
        }

        return $results['tests'][$test['file'].'::'.$test['name']]['status'] ?? 'missing';
    }

    private function render(array $catalogue, array $tags, ?array $results): string
    {
        $out = [];
        $out[] = '# '.__('evidence.title');
        $out[] = '';
        $out[] = __('evidence.intro');
        $out[] = '';
        $out[] = '> '.__('evidence.bar');
        $out[] = '';
        $out[] = __('evidence.names_note');
        $out[] = '';
        $out[] = '- **'.__('evidence.generated_at').'**: '.now()->toDateTimeString();
        $out[] = '- **'.__('evidence.commit').'**: '.$this->commit();
        $out[] = '- **'.__('evidence.environment').'**: PHP '.PHP_VERSION.' · '.config('database.default');

        if ($results !== null) {
            $line = __('evidence.suite_line', [
                'passed' => $results['totals']['passed'],
                'total' => $results['totals']['total'],
                'assertions' => $results['totals']['assertions'],
                'seconds' => $results['totals']['seconds'],
            ]);

            if ($results['totals']['skipped'] > 0) {
                $line .= '، '.__('evidence.suite_skipped', ['count' => $results['totals']['skipped']]);
            }

            $out[] = '- **'.__('evidence.suite').'**: '.$line;
        }

        $out[] = '';
        $out[] = $this->summary($catalogue);
        // T-96 — the bar before launch, said in the report rather than left to
        // be worked out from the summary table.
        $out[] = $this->gate($catalogue);
        $out[] = $this->detail($catalogue, $tags, $results);
        $out[] = $this->acceptance($catalogue, $tags, $results);
        $out[] = $this->open($catalogue);
        $out[] = $this->hosting($catalogue);
        $out[] = $this->afterHosting($catalogue);
        $out[] = '---';
        $out[] = '';
        $out[] = __('evidence.footer');
        $out[] = '';

        return implode("\n", $out);
    }

    private function summary(array $catalogue): string
    {
        $states = array_keys(__('evidence.state'));
        $rows = [];

        $header = '| '.__('evidence.col_section');
        foreach ($states as $state) {
            $header .= ' | '.__("evidence.state.{$state}");
        }
        $header .= ' | '.__('evidence.col_total').' |';

        $rows[] = $header;
        $rows[] = '|'.str_repeat('---|', count($states) + 2);

        $grand = array_fill_keys($states, 0);

        foreach ($catalogue['sections'] as $key => $name) {
            $items = $this->itemsOf($catalogue, $key);
            $line = '| '.$name.' ('.$key.')';

            foreach ($states as $state) {
                $count = $items->where('state', $state)->count();
                $grand[$state] += $count;
                $line .= ' | '.($count ?: '—');
            }

            $rows[] = $line.' | '.$items->count().' |';
        }

        $line = '| **'.__('evidence.col_total').'**';
        foreach ($states as $state) {
            $line .= ' | **'.$grand[$state].'**';
        }
        $rows[] = $line.' | **'.array_sum($grand).'** |';

        return '## '.__('evidence.summary')."\n\n".implode("\n", $rows)."\n";
    }

    private function detail(array $catalogue, array $tags, ?array $results): string
    {
        $out = ['## '.__('evidence.detail'), ''];

        foreach ($catalogue['sections'] as $key => $name) {
            $out[] = '### '.$name.' — '.$key;
            $out[] = '';

            foreach ($this->itemsOf($catalogue, $key) as $id => $item) {
                $out[] = '#### '.$id.' · '.$item['title'];
                $out[] = '';
                $out[] = '`'.__('evidence.col_state').': '.__("evidence.state.{$item['state']}").'`'
                    .' · `'.__('evidence.col_priority').': '.$item['priority'].'`'
                    .' · `'.__('evidence.col_scope').': '.__("evidence.scope.{$item['scope']}").'`';
                $out[] = '';

                $proofs = $tags[$id] ?? [];

                if ($proofs === []) {
                    $out[] = $item['scope'] === 'app'
                        ? __('evidence.no_evidence_app')
                        : __('evidence.no_evidence');
                    $out[] = '';
                } else {
                    $out[] = '| '.__('evidence.col_test').' | '.__('evidence.col_result').' |';
                    $out[] = '|---|---|';

                    foreach ($proofs as $test) {
                        $status = $this->statusOf($test, $results);
                        $mark = match ($status) {
                            'passed' => '✔',
                            'untested' => '·',
                            default => '✘',
                        };
                        $where = Str::of($test['file'])->afterLast('/')->before('.php');

                        $out[] = '| `'.$where.'` — '.$test['name']
                            .' | '.$mark.' '.__("evidence.result.{$status}").' |';
                    }

                    $out[] = '';
                    $out[] = '<sub>'.__('evidence.rerun').': `./vendor/bin/pest --group='.$id.'`</sub>';
                    $out[] = '';
                }

                if (! empty($item['gap'])) {
                    $out[] = '**'.__('evidence.gap').':** '.$item['gap'];
                    $out[] = '';
                }

                /*
                 | T-93 — an item answered by an absence says when the absence
                 | ends. «No payment gateway, so no webhook» is true today and
                 | a lie the day one is connected, and the association asked
                 | for that in writing on 14 October rather than left to
                 | memory.
                 */
                if (! empty($item['reactivates'])) {
                    $out[] = '**'.__('evidence.reactivates').':** '.$item['reactivates'];
                    $out[] = '';
                }
            }
        }

        return implode("\n", $out);
    }

    private function acceptance(array $catalogue, array $tags, ?array $results): string
    {
        $out = ['## '.__('evidence.acceptance'), '', __('evidence.acceptance_intro'), ''];
        $out[] = '| '.__('evidence.col_item').' | '.__('evidence.col_title').' | '.__('evidence.col_covers')
            .' | '.__('evidence.col_state').' | '.__('evidence.col_evidence').' |';
        $out[] = '|---|---|---|---|---|';

        foreach ($catalogue['acceptance'] as $id => $test) {
            $covered = collect($test['covers']);

            /*
             | An acceptance test is only as done as the weakest item under it,
             | and an item that does not apply yet is not a pass: AT-09 covers
             | a replay we do prevent and a forged webhook that cannot reach a
             | gateway we do not have, so the row reads جزئي, not تم.
             */
            $states = $covered->map(fn ($item) => $catalogue['items'][$item]['state']);
            $state = match (true) {
                $states->contains('pending') => 'pending',
                $states->contains('partial') => 'partial',
                $states->every(fn ($s) => $s === 'not_applicable') => 'not_applicable',
                $states->contains('not_applicable') => 'partial',
                default => 'done',
            };

            $passing = $covered
                ->flatMap(fn ($item) => $tags[$item] ?? [])
                ->filter(fn ($t) => in_array($this->statusOf($t, $results), ['passed', 'untested'], true))
                ->count();

            $out[] = '| '.$id.' | '.$test['title'].' | '.$covered->implode('، ')
                .' | '.__("evidence.state.{$state}")
                .' | '.($passing ?: '—').' |';
        }

        return implode("\n", $out)."\n";
    }

    /*
     | T-96 — the bar, and where the system stands against it. Written as the
     | association put it on 14 October: no P0 left unstarted, and any P0 left
     | partial saying what is missing, why, and whether the deferral is real.
     */
    private function gate(array $catalogue): string
    {
        $open = $this->openP0($catalogue);
        $unstarted = $open->where('state', 'pending');
        $met = $unstarted->isEmpty();

        $out = ['## '.__('evidence.gate.title'), ''];
        $out[] = '> '.__('evidence.gate.bar');
        $out[] = '';
        $out[] = '**'.__('evidence.gate.verdict').':** '
            .($met ? __('evidence.gate.met') : __('evidence.gate.not_met', ['count' => $unstarted->count()]));
        $out[] = '';

        // Grouped by the verdict rather than by section: the question here is
        // «what stands between this and launch», and it is answered by who
        // holds each one, not by which chapter of the file it came from.
        foreach (array_keys(__('evidence.defer')) as $verdict) {
            $items = $open->where('defer', $verdict);

            if ($items->isEmpty()) {
                continue;
            }

            $out[] = '### '.__("evidence.defer.{$verdict}").' — '.$items->count();
            $out[] = '';
            $out[] = '| '.__('evidence.col_item').' | '.__('evidence.col_title').' | '.__('evidence.col_state')
                .' | '.__('evidence.gate.why').' |';
            $out[] = '|---|---|---|---|';

            foreach ($items as $id => $item) {
                $out[] = '| '.$id.' | '.$item['title'].' | '.__("evidence.state.{$item['state']}")
                    .' | '.($item['why'] ?? '—').' |';
            }

            $out[] = '';
        }

        return implode("\n", $out);
    }

    /*
     | T-96 — the hosting annex the association asked for in writing: for every
     | item that waits on the environment, how it will be done, who does it,
     | what it costs and how it is tested once installed. It lives beside the
     | items rather than in a document of its own, because a proposal kept
     | somewhere else drifts from the catalogue inside a week.
     */
    private function hosting(array $catalogue): string
    {
        $plan = $catalogue['hosting_plan'] ?? [];

        if ($plan === []) {
            return '';
        }

        $out = ['## '.__('evidence.hosting.title'), '', __('evidence.hosting.intro'), ''];
        $out[] = '> '.$plan['note'];
        $out[] = '';

        foreach ($plan as $id => $entry) {
            if ($id === 'note') {
                continue;
            }

            $item = $catalogue['items'][$id] ?? null;

            $out[] = '### '.$id.' · '.($item['title'] ?? '');
            $out[] = '';
            $out[] = '`'.__('evidence.col_state').': '.__('evidence.state.'.($item['state'] ?? 'pending')).'`'
                .' · `'.__('evidence.col_priority').': '.($item['priority'] ?? '—').'`';
            $out[] = '';
            $out[] = '| | |';
            $out[] = '|---|---|';
            $out[] = '| **'.__('evidence.hosting.how').'** | '.$entry['how'].' |';
            $out[] = '| **'.__('evidence.hosting.responsible').'** | '.$entry['responsible'].' |';
            $out[] = '| **'.__('evidence.hosting.cost').'** | '.$entry['cost'].' |';
            $out[] = '| **'.__('evidence.hosting.verify').'** | '.$entry['verify'].' |';
            $out[] = '';
        }

        return implode("\n", $out);
    }

    /** T-96 — what is re-run once an environment close to production exists. */
    private function afterHosting(array $catalogue): string
    {
        $steps = $catalogue['after_hosting'] ?? [];

        if ($steps === []) {
            return '';
        }

        $out = ['## '.__('evidence.after_hosting.title'), '', __('evidence.after_hosting.intro'), ''];

        foreach ($steps as $index => $step) {
            $out[] = '**'.($index + 1).'. '.$step['title'].'** — '.$step['body'];
            $out[] = '';
        }

        return implode("\n", $out);
    }

    private function open(array $catalogue): string
    {
        $out = ['## '.__('evidence.open'), ''];

        foreach (array_keys(__('evidence.scope')) as $scope) {
            $items = collect($catalogue['items'])
                ->filter(fn ($item) => $item['scope'] === $scope && $item['state'] !== 'done');

            $out[] = '### '.__("evidence.open_{$scope}");
            $out[] = '';

            if ($items->isEmpty()) {
                $out[] = __('evidence.open_none');
                $out[] = '';

                continue;
            }

            foreach ($items as $id => $item) {
                $out[] = '- **'.$id.'** '.$item['title'].' ('.__("evidence.state.{$item['state']}").') — '
                    .($item['gap'] ?? '');
            }

            $out[] = '';
        }

        return implode("\n", $out);
    }

    /** @return Collection<string, array> */
    private function itemsOf(array $catalogue, string $section)
    {
        return collect($catalogue['items'])->filter(
            fn ($item, $id) => Str::before($id, '-') === $section
        );
    }

    private function commit(): string
    {
        $result = Process::run(['git', 'rev-parse', '--short', 'HEAD']);

        return $result->successful() ? trim($result->output()) : '—';
    }
}
