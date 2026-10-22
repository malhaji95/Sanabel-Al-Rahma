<?php

use App\Services\QrCode;
use App\Services\Totp;

/*
 | T-119 — «من اجل 2fa اظهر كيو ار كود ليتم تصويره من قبل تطبيق المصادقة»,
 | 22 October.
 |
 | An encoder nobody can check is an encoder nobody should trust, so this
 | holds the two things that can be checked without a camera.
 |
 | The first is the published constants: the fifteen format bits for each of
 | the eight masks are a table in ISO/IEC 18004, printed here as the standard
 | prints them. A code whose format bits are wrong cannot be read at all, and
 | a reader of this file can compare them against the standard by eye.
 |
 | The second is a matrix, module for module. It was produced by this encoder
 | and then read back by two independent decoders — zxing-cpp, the engine most
 | phone scanners are built on, decoded every length from one to four hundred
 | and twelve characters, and both of the provisioning URIs below. The one
 | written out here is the shortest of them, so a change that breaks the
 | encoder breaks this test rather than somebody's enrolment.
 */

/** Verified by zxing-cpp to read back as «HELLO». */
const HELLO_V1_M = [
    '111111101101001111111',
    '100000100110101000001',
    '101110100111101011101',
    '101110101001001011101',
    '101110101000101011101',
    '100000101011001000001',
    '111111101010101111111',
    '000000001111100000000',
    '100010111111011111001',
    '000111001011100101111',
    '101100101011001110010',
    '111001000100011010000',
    '001011100100111000110',
    '000000001110111001011',
    '111111101100110001010',
    '100000100001100100010',
    '101110101001001110101',
    '101110100001100001011',
    '101110100111001111000',
    '100000100100011000000',
    '111111101000111110101',
];

function matrixAsRows(string $text): array
{
    return array_map(
        fn (array $row) => implode('', array_map(fn (bool $dark) => $dark ? '1' : '0', $row)),
        app(QrCode::class)->matrix($text),
    );
}

it('draws a code a scanner can read, module for module', function () {
    expect(matrixAsRows('HELLO'))->toBe(HELLO_V1_M);
});

it('writes the format information the standard prints for each mask', function () {
    /*
     | ISO/IEC 18004 table 13, the error correction level M rows. These are
     | what tell a scanner which mask was used; everything else in the code
     | is unreadable without them.
     */
    $expected = [
        0 => '101010000010010',
        1 => '101000100100101',
        2 => '101111001111100',
        3 => '101101101001011',
        4 => '100010111111001',
        5 => '100000011001110',
        6 => '100111110010111',
        7 => '100101010100000',
    ];

    $method = new ReflectionMethod(QrCode::class, 'placeFormat');
    $method->setAccessible(true);

    foreach ($expected as $mask => $bits) {
        // An empty field, so what the method writes is all that is read back.
        $matrix = array_fill(0, 21, array_fill(0, 21, false));
        $method->invokeArgs(app(QrCode::class), [&$matrix, $mask]);

        // The first copy: six modules down column eight, then the corner,
        // then along row eight. Read back in the order the standard writes.
        $read = '';

        for ($i = 14; $i >= 9; $i--) {
            $read .= $matrix[8][14 - $i] ? '1' : '0';
        }

        $read .= $matrix[8][7] ? '1' : '0';
        $read .= $matrix[8][8] ? '1' : '0';
        $read .= $matrix[7][8] ? '1' : '0';

        for ($i = 5; $i >= 0; $i--) {
            $read .= $matrix[$i][8] ? '1' : '0';
        }

        expect($read)->toBe($bits, "the format bits for mask {$mask}");
    }
});

it('puts the three finders, the timing lines and the dark module where they belong', function () {
    $rows = matrixAsRows('HELLO');
    $size = count($rows);

    // A finder is seven modules of dark, light, three dark, light, dark.
    foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$top, $left]) {
        expect(substr($rows[$top], $left, 7))->toBe('1111111')
            ->and(substr($rows[$top + 1], $left, 7))->toBe('1000001')
            ->and(substr($rows[$top + 2], $left, 7))->toBe('1011101');
    }

    // The timing lines alternate, and the module beside the lower finder is
    // always dark.
    for ($i = 8; $i < $size - 8; $i++) {
        expect($rows[6][$i])->toBe($i % 2 === 0 ? '1' : '0')
            ->and($rows[$i][6])->toBe($i % 2 === 0 ? '1' : '0');
    }

    expect($rows[$size - 8][8])->toBe('1');
});

it('grows only as far as the text needs, and refuses what will not fit', function () {
    // The boundaries of each version, which is where an off-by-one in the
    // capacity table would show.
    foreach ([
        1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84,
        6 => 106, 7 => 122, 8 => 152, 9 => 180,
        // From here the character count is written in sixteen bits, not eight.
        10 => 213, 11 => 251, 12 => 287, 13 => 331, 14 => 362, 15 => 412,
    ] as $version => $capacity) {
        $size = $version * 4 + 17;

        expect(count(matrixAsRows(str_repeat('A', $capacity))))->toBe($size)
            ->and(count(matrixAsRows(str_repeat('A', $capacity - 1))))->toBe($size);
    }

    expect(fn () => app(QrCode::class)->matrix(str_repeat('A', 413)))
        ->toThrow(RuntimeException::class);
});

it('carries the whole provisioning URI, issuer in Arabic and all', function () {
    /*
     | The thing it is actually for. The association's name percent-encodes to
     | six characters a letter and appears in the URI twice, so the real string
     | is around two hundred and sixty characters — which is why the ceiling is
     | four hundred and not two hundred.
     */
    $uri = app(Totp::class)->provisioningUri(
        'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP',
        'treasurer@association.example',
        'سنابل الوفاء',
    );

    expect(strlen($uri))->toBeGreaterThan(200);

    $rows = matrixAsRows($uri);

    // Version 12 at this length, and square.
    expect(count($rows))->toBe(65)
        ->and(strlen($rows[0]))->toBe(65);
});

it('draws an SVG that is dark on white whichever scheme the page is in', function () {
    $svg = app(QrCode::class)->svg('HELLO', moduleSize: 4, quietZone: 4);

    // 21 modules and four of quiet either side, at four pixels each.
    expect($svg)->toContain('width="116" height="116"')
        ->and($svg)->toContain('viewBox="0 0 116 116"')
        // White behind it always: a scanner reads dark on light, and the one
        // person using this screen is the one who cannot work around it.
        ->and($svg)->toContain('<rect width="116" height="116" fill="#ffffff"/>')
        ->and($svg)->toContain('fill="#000000"')
        // Crisp, so the modules do not blur into each other when scaled.
        ->and($svg)->toContain('shape-rendering="crispEdges"')
        // One path for every dark module, not one element each.
        ->and(substr_count($svg, '<path'))->toBe(1);
});

it('shows the enrolling account a code to photograph, and the key only as a fallback', function () {
    seedCore();

    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));

    $admin = userWithRole('admin');

    $page = Livewire\Livewire::actingAs($admin)
        ->test(App\Filament\Pages\TwoFactor::class)
        ->assertSuccessful();

    $secret = $page->get('pendingSecret');
    $html = $page->html();

    expect($html)->toContain('<svg')
        ->and($html)->toContain('shape-rendering="crispEdges"')
        // The key stays, because a camera is not always available.
        ->and($html)->toContain($secret)
        // But the raw otpauth line is gone: it is a secret written out in
        // full, and the code is what it was being shown for.
        ->and($html)->not->toContain('otpauth://');
})->group('IAM-01');
