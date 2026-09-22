<?php

use Illuminate\Support\Facades\Lang;

/**
 * A translation key that no longer resolves renders as its own raw key on the
 * page — `sanabel.member.person_class` where a column heading belongs. Nothing
 * throws, so every other test stays green. These two guards close that gap.
 */
it('declares every translation key once, so none is silently overwritten', function () {
    // PHP accepts a duplicate key in an array literal and keeps only the last
    // one. `lang/ar/sanabel.php` once declared 'member' twice; the second group
    // (memberships) wiped the first (household members) and ten labels started
    // rendering as raw keys.
    $duplicates = [];

    foreach (glob(lang_path('*/*.php')) as $file) {
        $frames = [];
        $trail = [];
        $pending = null;
        $lastKey = null;

        foreach (token_get_all(file_get_contents($file)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $pending = [substr($token[1], 1, -1), $token[2]];

                    continue;
                }

                if ($token[0] === T_DOUBLE_ARROW) {
                    if ($pending !== null && $frames !== []) {
                        [$key, $line] = $pending;
                        $frame = count($frames) - 1;

                        if (isset($frames[$frame][$key])) {
                            $path = array_filter($trail);
                            $duplicates[] = basename(dirname($file)).'/'.basename($file).': '
                                .implode('.', $path).($path ? '.' : '').$key
                                ." on lines {$frames[$frame][$key]} and {$line}";
                        }

                        $frames[$frame][$key] = $line;
                        $lastKey = $key;
                    }

                    $pending = null;

                    continue;
                }

                $pending = null;

                continue;
            }

            if ($token === '[') {
                $frames[] = [];
                $trail[] = $lastKey;
                $lastKey = null;
            } elseif ($token === ']') {
                array_pop($frames);
                array_pop($trail);
            }

            $pending = null;
        }
    }

    expect($duplicates)->toBeEmpty();
});

it('resolves every translation key the panels and views ask for', function () {
    $used = [];

    foreach (['app', 'resources/views'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir)));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            // Literal keys only. A key holding an interpolated variable cannot
            // be resolved by reading the source.
            preg_match_all(
                '/__\(\s*([\'"])((?:sanabel|notifications|validation)\.[^\'"$]*?)\1/',
                $contents,
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                $used[$match[2]][] = $file->getPathname();
            }
        }
    }

    // A scan that suddenly finds almost nothing means the regex broke, not
    // that the panels stopped translating.
    expect(count($used))->toBeGreaterThan(300);

    $missing = [];

    foreach ($used as $key => $files) {
        if (Lang::has($key, 'ar')) {
            continue;
        }

        // Keys built by concatenation — __('sanabel.status.'.$state) — reach
        // here as the literal prefix. The group has to exist and hold at least
        // one key that could complete it.
        $cut = strrpos($key, '.');
        $group = $cut === false ? null : Lang::get(substr($key, 0, $cut), [], 'ar');
        $fragment = $cut === false ? $key : substr($key, $cut + 1);

        $completable = is_array($group) && collect(array_keys($group))
            ->contains(fn ($child) => str_starts_with((string) $child, $fragment));

        if (! $completable) {
            $missing[$key] = array_values(array_unique(array_map('basename', $files)));
        }
    }

    expect($missing)->toBeEmpty();
});
