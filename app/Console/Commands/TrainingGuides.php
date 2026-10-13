<?php

namespace App\Console\Commands;

use App\Models\Beneficiary;
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

            if (($code = $this->capture($guides)) !== self::SUCCESS) {
                return $code;
            }
        }

        $missed = $this->missed();

        foreach ($guides as $key => $guide) {
            $this->write($key, $guide, $missed);
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
                    ->map(fn ($step) => ['key' => $step['key'], 'path' => $step['path'], 'full' => (bool) ($step['full'] ?? false)])
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

    private function write(string $key, array $guide, array $missed): void
    {
        $steps = collect($guide['steps'])
            ->map(function ($step) {
                $shot = $this->out.'/shots/'.$step['key'].'.png';

                return $step + [
                    'image' => File::exists($shot)
                        ? 'data:image/png;base64,'.base64_encode(File::get($shot))
                        : null,
                ];
            })
            ->all();

        $html = view('training.guide', [
            'role' => $key,
            'guide' => $guide,
            'steps' => $steps,
            'generatedAt' => now(),
            'commit' => $this->commit(),
        ])->render();

        $name = $key.'-'.Str::slug($guide['name_ar'], '-', null);
        $path = $this->out.'/'.$name.'.html';
        File::put($path, $html);

        if (! $this->option('no-pdf')) {
            Process::timeout(300)->run(['node', base_path('training/capture.mjs'), 'pdf', $path, $this->out.'/'.$name.'.pdf']);
        }

        $this->info(__('training.cli.written', ['role' => $guide['name_ar'], 'path' => $path]));
    }

    private function commit(): string
    {
        $result = Process::run(['git', 'rev-parse', '--short', 'HEAD']);

        return $result->successful() ? trim($result->output()) : '—';
    }
}
