<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 | T-77 — the report as a page, so it can be handed over.
 |
 | Markdown belongs beside the code; an association's board is handed a PDF.
 | These cover the part the printer cannot: that the page carries its own
 | typeface and the identity's colours rather than hardcoded ones, that the
 | report's tables survive the rendering, and that nothing is lost between the
 | two shapes.
 */

it('renders the report as a page with its tables intact', function () {
    $markdown = <<<'MD'
    # تقرير

    > المعيار

    ## الملخّص

    | المجموعة | تم |
    |---|---|
    | الهوية | 3 |

    #### AUTH-02 · فحص ملكية السجل

    `الحالة: تم`

    | الاختبار | النتيجة |
    |---|---|
    | `PermissionsTest` — كذا | ✔ نجح |

    <sub>لإعادة التحقّق: `./vendor/bin/pest --group=AUTH-02`</sub>
    MD;

    $html = view('evidence.report', [
        'body' => Str::markdown($markdown, ['html_input' => 'allow']),
    ])->render();

    // The content, including the tables a reader scans first.
    expect($html)->toContain('<table>')
        ->and($html)->toContain('AUTH-02')
        ->and($html)->toContain('✔ نجح')
        ->and($html)->toContain('<blockquote>')
        // The re-run hint is raw HTML in the Markdown; it must survive.
        ->and($html)->toContain('<sub>')
        ->and($html)->toContain('dir="rtl"');
});

it('carries its own typeface, so the file reads the same on any machine', function () {
    $html = view('evidence.report', ['body' => '<p>ا</p>'])->render();

    expect($html)->toContain('@font-face')
        ->and($html)->toContain('data:font/woff2;base64,')
        // And no stylesheet or font fetched from anywhere.
        ->and($html)->not->toContain('<link ')
        ->and($html)->not->toContain('http://')
        ->and($html)->not->toContain('https://');
})->group('APP-02');

it('takes the identity colours from the brand config, not from the page', function () {
    $html = view('evidence.report', ['body' => '<p>ا</p>'])->render();

    foreach (['primary', 'gold', 'ink', 'surface'] as $token) {
        expect($html)->toContain(config("brand.colors.{$token}"));
    }
});

it('prints beside the report it was made from, under the same name', function () {
    // One report in two shapes: the names differ only by their extension, so
    // nobody has to wonder which run a PDF came from.
    $source = File::get(app_path('Console/Commands/EvidenceReport.php'));

    expect(str_contains($source, "str_replace('.md', '.pdf', \$path)"))->toBeTrue()
        ->and(str_contains($source, "str_replace('.md', '.html', \$path)"))->toBeTrue();
});
