<?php

namespace App\Console\Commands;

use App\Models\Beneficiary;
use App\Services\PermissionService;
use App\Services\RankingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/*
 | T-72 — a training guide per role, generated rather than written.
 |
 | The association asked for a tour of the system per user, following the order
 | the work is actually done in. Screenshots pasted into a document go stale on
 | the first screen that moves, and a training file that shows a screen the
 | trainee will not see is worse than none. So this photographs the live system
 | role by role and assembles the guides from resources/training/guides.php.
 |
 | T-92 — and it writes three things no screenshot can say:
 |   · «صلاحيات حسابك» at the end of every guide, read from the permission rows
 |     themselves so a guide cannot promise a power the system withholds;
 |   · the whole matrix as a printed sheet, in the guides of the roles that
 |     answer for the system as a whole;
 |   · «نظرة عامة على المنصّة» — the document before the guides, from
 |     resources/training/overview.php.
 |
 | It needs the application served (php artisan serve) and Playwright reachable
 | on NODE_PATH; it says so plainly rather than producing empty guides.
 */
class TrainingGuides extends Command
{
    protected $signature = 'training:guides
        {--role=* : Build only these roles}
        {--skip-shots : Reuse the screenshots already taken}
        {--no-pdf : Write the HTML and skip printing}';

    protected $description = 'Build a per-role training tour of the system, with screenshots';

    private string $out;

    public function handle(): int
    {
        $this->out = storage_path('app/training');
        File::ensureDirectoryExists($this->out.'/shots');

        $guides = $this->guides();

        if ($guides === []) {
            $this->error(__('training.cli.no_such_role'));

            return self::FAILURE;
        }

        if (! $this->option('skip-shots')) {
            if (! $this->serving()) {
                $this->error(__('training.cli.not_serving'));

                return self::FAILURE;
            }

            // The overview's two pictures are public screens, so they are
            // photographed in the same pass, signed in as nobody.
            $shots = $guides;

            if ($this->option('role') === []) {
                $shots['overview'] = ['steps' => $this->overview()['steps']];
            }

            if (($code = $this->capture($shots)) !== self::SUCCESS) {
                return $code;
            }
        }

        $missed = $this->missed();

        foreach ($guides as $key => $guide) {
            $this->write($key, $guide);
        }

        if ($this->option('role') === []) {
            $this->writeOverview($guides);
        }

        if ($missed !== []) {
            $this->warn(__('training.cli.missed', ['count' => count($missed)]));

            foreach ($missed as $miss) {
                $this->line('  '.$miss);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** The guides asked for, with the record tokens resolved to real paths. */
    private function guides(): array
    {
        $guides = require resource_path('training/guides.php');
        $only = $this->option('role');

        if ($only !== []) {
            $guides = array_intersect_key($guides, array_flip($only));
        }

        foreach ($guides as $key => $guide) {
            $steps = [];

            foreach ($guide['steps'] as $step) {
                $path = $this->resolve($step['path']);

                if ($path === null) {
                    $this->warn(__('training.cli.no_record', ['step' => $step['key'], 'path' => $step['path']]));

                    continue;
                }

                $steps[] = ['path' => $path] + $step;
            }

            $guides[$key]['steps'] = $steps;
        }

        return $guides;
    }

    /**
     * {case:verified} becomes the id of the file standing at that stage, so
     * the screen photographed has real work on it. TrainingStageSeeder leaves
     * one file at each stage for exactly this.
     */
    private function resolve(string $path): ?string
    {
        if (! preg_match('/\{(case|file):([a-z_]+)\}/', $path, $m)) {
            return $path;
        }

        $case = $m[2] === 'published'
            ? $this->openOpportunity() ?? Beneficiary::where('status', 'published')->orderBy('id')->first()
            : Beneficiary::where('status', $m[2])->orderBy('id')->first();

        if (! $case) {
            return null;
        }

        return str_replace($m[0], $m[1] === 'case' ? (string) $case->getKey() : $case->file_number, $path);
    }

    /**
     * A published file a donor could actually give to.
     *
     * The first published file by id is not necessarily one of them: a file
     * whose month is answered, or whose remainder is held, answers the
     * opportunity page with «اكتملت تغطية هذه الفرصة». The guide then
     * photographed a dead end and called it the case page — which is what it
     * did until this was noticed in the capture. The funding list is the
     * donor's own view, so it is the right place to ask.
     */
    private function openOpportunity(): ?Beneficiary
    {
        $ranking = app(RankingService::class);
        // The head of the list is enough to find one, and the guide is built
        // against a demo set of a few dozen.
        $open = $ranking->hydrate($ranking->fundingList('monthly')->take(50))->pluck('beneficiary');

        // Preferring one with an account on file is not decoration: the guide
        // tells the trainee that the donor sees the last four digits of it,
        // and a page that cannot show them teaches the opposite.
        return $open->first(fn (Beneficiary $b) => filled($b->wallet_encrypted)) ?? $open->first();
    }

    private function serving(): bool
    {
        $base = env('TRAINING_BASE_URL', 'http://localhost:8000');

        return Process::run(['curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', $base])
            ->output() !== '000';
    }

    private function capture(array $guides): int
    {
        $manifest = $this->out.'/manifest.json';

        File::put($manifest, json_encode(
            collect($guides)->map(fn ($guide, $key) => [
                'key' => $key,
                'account' => $guide['account'] ?? null,
                'panel' => $guide['panel'] ?? null,
                'two_factor' => (bool) ($guide['two_factor'] ?? false),
                'steps' => collect($guide['steps'])
                    ->map(fn ($step) => [
                        'key' => $step['key'],
                        'path' => $step['path'],
                        'full' => (bool) ($step['full'] ?? false),
                        // The sign-in form is a screen the overview document
                        // shows on purpose; without this the capture would
                        // report it as a dropped session and skip it.
                        'login' => (bool) ($step['login'] ?? false),
                    ])
                    ->values(),
            ])->values(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ));

        $this->info(__('training.cli.capturing'));

        $result = Process::timeout(1800)->run([
            'node', base_path('training/capture.mjs'), 'shots', $manifest, $this->out.'/shots',
        ]);

        $this->line($result->output());

        if (! $result->successful()) {
            $this->error(__('training.cli.capture_failed'));
            $this->line($result->errorOutput());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function missed(): array
    {
        $file = $this->out.'/shots/capture-result.json';

        if (! File::exists($file)) {
            return [];
        }

        return json_decode(File::get($file), true)['missed'] ?? [];
    }

    private function write(string $key, array $guide): void
    {
        $permissions = app(PermissionService::class);

        $html = view('training.guide', [
            'role' => $key,
            'guide' => $guide,
            'steps' => $this->withShots($guide['steps']),
            // T-92 — read from the rows, not written beside them.
            'sheet' => $permissions->roleSheet($key),
            'matrix' => ($guide['matrix'] ?? false) ? $this->matrix() : null,
            'generatedAt' => now(),
            'commit' => $this->commit(),
        ])->render();

        $path = $this->put($key.'-'.Str::slug($guide['name_ar'], '-', null), $html);

        $this->info(__('training.cli.written', ['role' => $guide['name_ar'], 'path' => $path]));
    }

    /**
     * T-92 — the document before the guides.
     *
     * It carries no screen a role might not have: its two pictures are the
     * public home page and the sign-in form. The table of guides is built from
     * the guides themselves, so a guide added tomorrow appears in it without
     * anyone remembering to add a row.
     */
    private function writeOverview(array $guides): void
    {
        $overview = $this->overview();

        $html = view('training.overview', [
            'overview' => $overview,
            'steps' => $this->withShots($overview['steps']),
            'roles' => collect($guides)
                ->map(fn (array $guide) => [
                    'name_ar' => $guide['name_ar'],
                    'steps' => count($guide['steps']),
                    'summary_ar' => Str::before($guide['summary_ar'], '.').'.',
                ])
                ->values()
                ->all(),
            'matrix' => $this->matrix(),
            'generatedAt' => now(),
            'commit' => $this->commit(),
        ])->render();

        $path = $this->put('00-'.Str::slug(__('training.overview.title'), '-', null), $html);

        $this->info(__('training.cli.overview_written', ['path' => $path]));
    }

    private function overview(): array
    {
        return require resource_path('training/overview.php');
    }

    /**
     * The matrix as plain arrays: a printed sheet does not need models, and
     * building it once per run keeps 21 roles from being queried per page.
     *
     * @return array{roles: array<int,array{name_ar: string, read_only: bool}>, groups: array<string,array<int,array{name_ar: string, cells: array<int,?string>}>>}
     */
    private function matrix(): array
    {
        static $built = null;

        if ($built !== null) {
            return $built;
        }

        $permissions = app(PermissionService::class);
        $matrix = $permissions->matrix();

        $groups = [];

        foreach ($matrix['groups'] as $group => $rows) {
            foreach ($rows as $permission) {
                $groups[$group][] = [
                    'name_ar' => $permission->name_ar,
                    'cells' => $matrix['roles']
                        ->map(fn ($role) => $permissions->grantedScope($role, $permission))
                        ->all(),
                ];
            }
        }

        return $built = [
            'roles' => $matrix['roles']
                ->map(fn ($role) => ['name_ar' => $role->name_ar, 'read_only' => (bool) $role->is_read_only])
                ->all(),
            'groups' => $groups,
        ];
    }

    /** Each step with its screenshot embedded, or null where none was taken. */
    private function withShots(array $steps): array
    {
        return collect($steps)
            ->map(function (array $step) {
                $shot = $this->out.'/shots/'.$step['key'].'.png';

                return $step + [
                    'image' => File::exists($shot)
                        ? 'data:image/png;base64,'.base64_encode(File::get($shot))
                        : null,
                ];
            })
            ->all();
    }

    /** Writes the HTML, and prints the PDF beside it unless asked not to. */
    private function put(string $name, string $html): string
    {
        $path = $this->out.'/'.$name.'.html';
        File::put($path, $html);

        if (! $this->option('no-pdf')) {
            Process::timeout(300)->run(['node', base_path('training/capture.mjs'), 'pdf', $path, $this->out.'/'.$name.'.pdf']);
        }

        return $path;
    }

    private function commit(): string
    {
        $result = Process::run(['git', 'rev-parse', '--short', 'HEAD']);

        return $result->successful() ? trim($result->output()) : '—';
    }
}
