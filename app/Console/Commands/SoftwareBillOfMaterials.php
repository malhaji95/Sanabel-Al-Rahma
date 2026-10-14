<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 | T-94 — CICD-04. What is inside a release, listed by the release itself.
 |
 | The association's file asks for a software bill of materials with every
 | version. Until now the answer was «the lock files», which is true and
 | useless: nobody audits a release by reading two lock files in two formats,
 | and neither of them says which commit they belong to or when they were
 | installed. The day an advisory names a package, the question is whether the
 | version running in Daraa carries it, and that question needs one document
 | per release rather than a repository to clone.
 |
 | CycloneDX 1.5, which is the format every scanner reads, built from the lock
 | files and nothing else — a lock file is the only honest source, because it
 | is what `composer install` and `npm ci` actually lay down.
 */
class SoftwareBillOfMaterials extends Command
{
    protected $signature = 'sbom
        {--out= : Write here instead of storage/app/sbom}
        {--dev : Include the development dependencies, marked as excluded from the release}';

    protected $description = 'List every component this release ships, as CycloneDX JSON';

    public function handle(): int
    {
        $components = array_merge($this->composer(), $this->npm());

        if ($components === []) {
            $this->error(__('evidence.sbom.no_locks'));

            return self::FAILURE;
        }

        $commit = $this->commit();

        $document = [
            'bomFormat' => 'CycloneDX',
            'specVersion' => '1.5',
            // The commit is the serial number, so a document and a release
            // can always be put back together.
            'serialNumber' => 'urn:uuid:'.$this->uuidFrom($commit),
            'version' => 1,
            'metadata' => [
                'timestamp' => now()->toIso8601String(),
                'tools' => [[
                    'vendor' => 'Sanabel Al-Rahma',
                    'name' => 'artisan sbom',
                    'version' => $commit,
                ]],
                'component' => [
                    'type' => 'application',
                    'name' => 'sanabel-al-rahma',
                    'version' => $commit,
                    'description' => config('brand.tagline_ar'),
                ],
                'properties' => [
                    ['name' => 'php', 'value' => PHP_VERSION],
                ],
            ],
            'components' => $components,
        ];

        $path = $this->option('out') ?: storage_path('app/sbom/sbom-'.$commit.'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $this->info(__('evidence.sbom.written', ['count' => count($components), 'path' => $path]));

        return self::SUCCESS;
    }

    /** Everything composer.lock lays down, production first. */
    private function composer(): array
    {
        $lock = $this->read(base_path('composer.lock'));

        if ($lock === null) {
            return [];
        }

        $components = [];

        foreach ($this->sections($lock, 'packages', 'packages-dev') as $scope => $packages) {
            foreach ($packages as $package) {
                $components[] = array_filter([
                    'type' => 'library',
                    'name' => $package['name'],
                    'version' => $package['version'],
                    'purl' => 'pkg:composer/'.$package['name'].'@'.$package['version'],
                    'scope' => $scope,
                    'licenses' => $this->licences($package['license'] ?? []),
                    'description' => $package['description'] ?? null,
                ]);
            }
        }

        return $components;
    }

    /**
     * Everything package-lock.json lays down.
     *
     * The browser is served a compiled bundle and nothing out of node_modules,
     * so every one of these is a build tool. They are listed all the same: a
     * build tool that is compromised compiles the bundle, which is the supply
     * chain the item is about.
     */
    private function npm(): array
    {
        $lock = $this->read(base_path('package-lock.json'));

        if ($lock === null) {
            return [];
        }

        $components = [];

        foreach ($lock['packages'] ?? [] as $path => $package) {
            // The root entry is the application itself, listed as the
            // document's own component rather than as one of its parts.
            if ($path === '' || empty($package['version'])) {
                continue;
            }

            $name = str_replace('node_modules/', '', $path);

            if (! $this->option('dev') && ($package['dev'] ?? false)) {
                continue;
            }

            $components[] = array_filter([
                'type' => 'library',
                'name' => $name,
                'version' => $package['version'],
                'purl' => 'pkg:npm/'.$name.'@'.$package['version'],
                'scope' => ($package['dev'] ?? false) ? 'excluded' : 'required',
                'licenses' => $this->licences($package['license'] ?? []),
            ]);
        }

        return $components;
    }

    /**
     * The lock's two lists, with the development one named `excluded` — which
     * is CycloneDX's word for a component that is present while building and
     * not in what ships.
     *
     * @return array<string, array<int, array>>
     */
    private function sections(array $lock, string $production, string $development): array
    {
        $sections = ['required' => $lock[$production] ?? []];

        if ($this->option('dev')) {
            $sections['excluded'] = $lock[$development] ?? [];
        }

        return $sections;
    }

    /** @param array<int,string>|string $licence */
    private function licences(array|string $licence): array
    {
        return collect((array) $licence)
            ->filter()
            ->map(fn (string $id) => ['license' => ['id' => $id]])
            ->values()
            ->all();
    }

    private function read(string $path): ?array
    {
        if (! File::exists($path)) {
            $this->warn(__('evidence.sbom.no_lock', ['path' => basename($path)]));

            return null;
        }

        return json_decode(File::get($path), true);
    }

    /** A stable identifier for a release, derived from its commit. */
    private function uuidFrom(string $commit): string
    {
        $hash = md5($commit);

        return implode('-', [
            substr($hash, 0, 8), substr($hash, 8, 4), '5'.substr($hash, 13, 3),
            dechex(hexdec(substr($hash, 16, 1)) & 0x3 | 0x8).substr($hash, 17, 3),
            substr($hash, 20, 12),
        ]);
    }

    private function commit(): string
    {
        $result = Process::run(['git', 'rev-parse', '--short', 'HEAD']);

        return $result->successful() ? trim($result->output()) : 'unversioned';
    }
}
