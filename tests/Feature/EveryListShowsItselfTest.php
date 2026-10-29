<?php

/*
 | T-195 — «لازم تظهر الخيارات وممكن أختار منها بدون بحث, هذا بالنسبة لكل
 | خيارات البحث», 29 October.
 |
 | A Filament select built on a relationship and marked `searchable()` sends
 | no options to the browser until something is typed into it: the field opens
 | empty, under the words «ابدأ بالكتابة للبحث...». `preload()` is the switch
 | that fills it. This is a guard rather than a page test, because the rule is
 | «every list», and a new resource added next month must not quietly break it.
 */
it('preloads every searchable list that is built on a relationship', function () {
    $offenders = [];

    foreach (
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(app_path('Filament'))
        ) as $file
    ) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        // One select at a time: from `Select::make(` to the comma or semicolon
        // that closes its chain, so a later statement cannot lend it a method
        // it does not have.
        foreach (statementsOpenedBy($source, 'Select::make(') as [$offset, $statement]) {
            if (
                str_contains($statement, '->relationship(')
                && str_contains($statement, '->searchable()')
                && ! str_contains($statement, '->preload()')
            ) {
                $offenders[] = basename($file->getPathname())
                    .':'.(substr_count(substr($source, 0, $offset), "\n") + 1);
            }
        }
    }

    expect($offenders)->toBeEmpty();
});
