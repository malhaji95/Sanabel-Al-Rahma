<?php

namespace App\Services;

use RuntimeException;

/**
 * T-119 — a QR code, so the authenticator app is pointed at the camera rather
 * than at a thirty-two character secret typed by hand.
 *
 * Written here rather than installed, for the same reason `Totp` was (T-38):
 * CLAUDE.md §3 does not admit a package without asking, and the one thing this
 * code carries is the second factor's secret. A QR service on the open
 * internet would be handed that secret with every enrolment, which settles
 * that option whatever it would have saved.
 *
 * The scope is the narrowest that answers the need:
 *
 *   - byte mode only, which is what a URI is;
 *   - error correction level M, the level an authenticator QR is normally
 *     drawn at: a quarter of the code may be lost and it still reads;
 *   - versions 1 to 10, which carry up to 213 characters. The provisioning
 *     URI is about 120 with a long address, so the ceiling is twice what the
 *     need is, and anything past it is refused rather than drawn wrong.
 *
 * Nothing here is invented: it is ISO/IEC 18004, and `QrCodeTest` holds the
 * matrices an independent implementation produces for the same input, module
 * for module, so a mistake in it is a failing test rather than a code nobody
 * can scan.
 */
final class QrCode
{
    /** Error correction level M, as the two bits the format information carries. */
    private const EC_LEVEL_BITS = 0b00;

    /**
     * Per version: error correction codewords per block, then the blocks
     * themselves as [count, data codewords each]. ISO/IEC 18004 table 9.
     */
    private const BLOCKS = [
        1 => [10, [[1, 16]]],   // 14 characters
        2 => [16, [[1, 28]]],   // 26 characters
        3 => [26, [[1, 44]]],   // 42 characters
        4 => [18, [[2, 32]]],   // 62 characters
        5 => [24, [[2, 43]]],   // 84 characters
        6 => [16, [[4, 27]]],   // 106 characters
        7 => [18, [[4, 31]]],   // 122 characters
        8 => [22, [[2, 38], [2, 39]]],   // 152 characters
        9 => [22, [[3, 36], [2, 37]]],   // 180 characters
        10 => [26, [[4, 43], [1, 44]]],   // 213 characters
        11 => [30, [[1, 50], [4, 51]]],   // 251 characters
        12 => [22, [[6, 36], [2, 37]]],   // 287 characters
        13 => [22, [[8, 37], [1, 38]]],   // 331 characters
        14 => [24, [[4, 40], [5, 41]]],   // 362 characters
        15 => [24, [[5, 41], [5, 42]]],   // 412 characters
    ];

    /** Where the alignment patterns are centred, per version. */
    private const ALIGNMENT = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
        11 => [6, 30, 54],
        12 => [6, 32, 58],
        13 => [6, 34, 62],
        14 => [6, 26, 46, 66],
        15 => [6, 26, 48, 70],
    ];

    /** @var array<int,int> */
    private static array $exp = [];

    /** @var array<int,int> */
    private static array $log = [];

    /**
     * The code as an SVG element, dark modules on a white field.
     *
     * Always white behind it, on both schemes: a scanner reads dark-on-light,
     * and a code drawn in the dark scheme's own colours is a code that fails
     * in the hand of the one person trying to use it.
     */
    public function svg(string $text, int $moduleSize = 6, int $quietZone = 4): string
    {
        $matrix = $this->matrix($text);
        $size = count($matrix) + $quietZone * 2;
        $side = $size * $moduleSize;

        $path = '';

        foreach ($matrix as $row => $modules) {
            foreach ($modules as $column => $dark) {
                if ($dark) {
                    $path .= 'M'.(($column + $quietZone) * $moduleSize).' '
                        .(($row + $quietZone) * $moduleSize)
                        .'h'.$moduleSize.'v'.$moduleSize.'h-'.$moduleSize.'z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$side.'" height="'.$side.'" '
            .'viewBox="0 0 '.$side.' '.$side.'" shape-rendering="crispEdges" role="img">'
            .'<rect width="'.$side.'" height="'.$side.'" fill="#ffffff"/>'
            .'<path d="'.$path.'" fill="#000000"/>'
            .'</svg>';
    }

    /**
     * The code as rows of booleans, true where a module is dark.
     *
     * @return array<int,array<int,bool>>
     */
    public function matrix(string $text): array
    {
        $version = $this->version(strlen($text));
        $codewords = $this->codewords($text, $version);

        [$modules, $isFunction] = $this->template($version);

        $this->placeData($modules, $isFunction, $codewords);

        // Every mask is drawn and scored, and the least ugly one wins. The
        // rules are the standard's, and they exist so that a code never comes
        // out looking enough like a finder pattern to confuse a scanner.
        $best = null;
        $bestPenalty = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = $this->masked($modules, $isFunction, $mask);
            $this->placeFormat($candidate, $mask);

            $penalty = $this->penalty($candidate);

            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * How many bits the character count is given in.
     *
     * Byte mode writes the length in eight bits up to version 9 and in
     * sixteen from version 10, and a code that writes the wrong one is a
     * code that cannot be read at all.
     */
    private function countBits(int $version): int
    {
        return $version >= 10 ? 16 : 8;
    }

    /** The smallest version that holds this many bytes, or an exception. */
    private function version(int $length): int
    {
        foreach (self::BLOCKS as $version => [$ecPerBlock, $groups]) {
            $data = 0;

            foreach ($groups as [$count, $each]) {
                $data += $count * $each;
            }

            // The mode and the length sit in front of the data.
            if ($length <= intdiv($data * 8 - 4 - $this->countBits($version), 8)) {
                return $version;
            }
        }

        throw new RuntimeException('The text is too long for a version 15 code.');
    }

    /**
     * The data codewords and their error correction, interleaved as the
     * standard asks: one codeword from each block in turn, so damage to one
     * part of the printed code is spread across every block rather than
     * destroying one of them.
     *
     * @return array<int,int>
     */
    private function codewords(string $text, int $version): array
    {
        [$ecPerBlock, $groups] = self::BLOCKS[$version];

        $bits = '0100'                                        // byte mode
            .str_pad(decbin(strlen($text)), $this->countBits($version), '0', STR_PAD_LEFT);

        foreach (str_split($text) as $character) {
            $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
        }

        $capacity = 0;

        foreach ($groups as [$count, $each]) {
            $capacity += $count * $each * 8;
        }

        // The terminator, up to four zero bits, then zeros to the byte, then
        // the two pad codewords alternating for as long as there is room.
        $bits .= str_repeat('0', min(4, $capacity - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);

        $pad = ['11101100', '00010001'];

        for ($i = 0; strlen($bits) < $capacity; $i++) {
            $bits .= $pad[$i % 2];
        }

        $data = [];
        $ec = [];
        $offset = 0;

        foreach ($groups as [$count, $each]) {
            for ($i = 0; $i < $count; $i++) {
                $block = array_map(
                    fn (string $byte) => bindec($byte),
                    str_split(substr($bits, $offset * 8, $each * 8), 8),
                );

                $offset += $each;
                $data[] = $block;
                $ec[] = $this->remainder($block, $ecPerBlock);
            }
        }

        $out = [];

        foreach ([$data, $ec] as $part) {
            for ($i = 0; $i < max(array_map('count', $part)); $i++) {
                foreach ($part as $block) {
                    if (isset($block[$i])) {
                        $out[] = $block[$i];
                    }
                }
            }
        }

        return $out;
    }

    /** Reed-Solomon over GF(256): the remainder of the block by the generator. */
    private function remainder(array $block, int $degree): array
    {
        $this->tables();

        $generator = [1];

        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($generator) + 1, 0);

            /*
             | Multiplying by (x + α^i), with the highest power of x first:
             | the x term keeps its place as the polynomial grows by one, and
             | the constant term moves down to the next coefficient.
             */
            foreach ($generator as $index => $coefficient) {
                $next[$index] ^= $coefficient;
                $next[$index + 1] ^= $this->multiply($coefficient, self::$exp[$i]);
            }

            $generator = $next;
        }

        // The division itself only ever uses the generator below its leading
        // term, which is always one.
        $divisor = array_slice($generator, 1);

        $remainder = array_fill(0, $degree, 0);

        foreach ($block as $byte) {
            $factor = $byte ^ array_shift($remainder);
            $remainder[] = 0;
            $remainder = array_values($remainder);

            foreach ($divisor as $index => $coefficient) {
                $remainder[$index] ^= $this->multiply($coefficient, $factor);
            }
        }

        return $remainder;
    }

    private function multiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return self::$exp[(self::$log[$a] + self::$log[$b]) % 255];
    }

    /** The field's exponent and logarithm tables, built once. */
    private function tables(): void
    {
        if (self::$exp !== []) {
            return;
        }

        $value = 1;

        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $value;
            self::$log[$value] = $i;

            // x⁸ + x⁴ + x³ + x² + 1, the field's own polynomial.
            $value <<= 1;

            if ($value & 0x100) {
                $value ^= 0x11D;
            }
        }
    }

    /**
     * The patterns that are not data: finders, separators, timing, alignment,
     * the dark module and the space the format and version keep.
     *
     * @return array{0: array<int,array<int,bool>>, 1: array<int,array<int,bool>>}
     */
    private function template(int $version): array
    {
        $size = $version * 4 + 17;

        $modules = array_fill(0, $size, array_fill(0, $size, false));
        $isFunction = $modules;

        $set = function (int $row, int $column, bool $dark) use (&$modules, &$isFunction): void {
            $modules[$row][$column] = $dark;
            $isFunction[$row][$column] = true;
        };

        /*
         | The three finders, given by their centres, each with the light
         | separator around it: dark at the centre, the ring at three, and
         | light at two and at four — which is the separator.
         */
        foreach ([[3, 3], [3, $size - 4], [$size - 4, 3]] as [$centreRow, $centreColumn]) {
            for ($row = -4; $row <= 4; $row++) {
                for ($column = -4; $column <= 4; $column++) {
                    $y = $centreRow + $row;
                    $x = $centreColumn + $column;

                    if ($y < 0 || $y >= $size || $x < 0 || $x >= $size) {
                        continue;
                    }

                    $distance = max(abs($row), abs($column));

                    $set($y, $x, $distance !== 2 && $distance !== 4);
                }
            }
        }

        // The two timing lines, which tell a scanner how wide a module is.
        for ($i = 0; $i < $size; $i++) {
            if (! $isFunction[6][$i]) {
                $set(6, $i, $i % 2 === 0);
            }

            if (! $isFunction[$i][6]) {
                $set($i, 6, $i % 2 === 0);
            }
        }

        /*
         | The alignment patterns, at every crossing of the centres except
         | the three corners a finder already occupies. The exception is by
         | position in the list and not by what is already drawn: from
         | version 7 a centre line crosses the timing pattern, and a pattern
         | skipped there is a code no scanner can line up.
         */
        $centres = self::ALIGNMENT[$version];
        $last = count($centres) - 1;

        foreach ($centres as $rowIndex => $row) {
            foreach ($centres as $columnIndex => $column) {
                $corner = ($rowIndex === 0 && $columnIndex === 0)
                    || ($rowIndex === 0 && $columnIndex === $last)
                    || ($rowIndex === $last && $columnIndex === 0);

                if ($corner) {
                    continue;
                }

                for ($i = -2; $i <= 2; $i++) {
                    for ($j = -2; $j <= 2; $j++) {
                        $set($row + $i, $column + $j, max(abs($i), abs($j)) !== 1);
                    }
                }
            }
        }

        // The module that is always dark, and the strips the format keeps.
        $set($size - 8, 8, true);

        for ($i = 0; $i < 9; $i++) {
            if (! $isFunction[8][$i]) {
                $set(8, $i, false);
            }

            if (! $isFunction[$i][8]) {
                $set($i, 8, false);
            }
        }

        for ($i = 0; $i < 8; $i++) {
            if (! $isFunction[8][$size - 1 - $i]) {
                $set(8, $size - 1 - $i, false);
            }

            if (! $isFunction[$size - 1 - $i][8]) {
                $set($size - 1 - $i, 8, false);
            }
        }

        // From version 7 the version itself is written twice, in a block
        // beside each of the two lower finders.
        if ($version >= 7) {
            $bits = $version;

            for ($i = 0; $i < 12; $i++) {
                $bits = ($bits << 1) ^ (($bits >> 11) * 0x1F25);
            }

            $bits = ($version << 12) | $bits;

            for ($i = 0; $i < 18; $i++) {
                $dark = (bool) (($bits >> $i) & 1);
                $a = $size - 11 + $i % 3;
                $b = intdiv($i, 3);

                $set($b, $a, $dark);
                $set($a, $b, $dark);
            }
        }

        return [$modules, $isFunction];
    }

    /** The data, snaked up and down two columns at a time from the right. */
    private function placeData(array &$modules, array $isFunction, array $codewords): void
    {
        $size = count($modules);
        $bit = 0;
        $total = count($codewords) * 8;

        for ($right = $size - 1; $right >= 1; $right -= 2) {
            // Column six is a timing line and is stepped over, not written in.
            if ($right === 6) {
                $right = 5;
            }

            for ($vertical = 0; $vertical < $size; $vertical++) {
                for ($j = 0; $j < 2; $j++) {
                    $column = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $row = $upward ? $size - 1 - $vertical : $vertical;

                    if ($isFunction[$row][$column] || $bit >= $total) {
                        continue;
                    }

                    $modules[$row][$column] = (bool) (($codewords[$bit >> 3] >> (7 - ($bit & 7))) & 1);
                    $bit++;
                }
            }
        }
    }

    /** The same matrix with one mask applied to everything that is not a pattern. */
    private function masked(array $modules, array $isFunction, int $mask): array
    {
        $size = count($modules);

        for ($row = 0; $row < $size; $row++) {
            for ($column = 0; $column < $size; $column++) {
                if ($isFunction[$row][$column]) {
                    continue;
                }

                $flip = match ($mask) {
                    0 => ($row + $column) % 2 === 0,
                    1 => $row % 2 === 0,
                    2 => $column % 3 === 0,
                    3 => ($row + $column) % 3 === 0,
                    4 => (intdiv($row, 2) + intdiv($column, 3)) % 2 === 0,
                    5 => ($row * $column) % 2 + ($row * $column) % 3 === 0,
                    6 => (($row * $column) % 2 + ($row * $column) % 3) % 2 === 0,
                    7 => ((($row + $column) % 2) + ($row * $column) % 3) % 2 === 0,
                };

                $modules[$row][$column] = $modules[$row][$column] !== $flip;
            }
        }

        return $modules;
    }

    /** The fifteen bits that say which level and which mask, written twice. */
    private function placeFormat(array &$modules, int $mask): void
    {
        $size = count($modules);
        $data = (self::EC_LEVEL_BITS << 3) | $mask;
        $bits = $data;

        for ($i = 0; $i < 10; $i++) {
            $bits = ($bits << 1) ^ (($bits >> 9) * 0x537);
        }

        $bits = (($data << 10) | $bits) ^ 0x5412;

        // The first copy, around the top-left finder.
        for ($i = 0; $i <= 5; $i++) {
            $modules[$i][8] = (bool) (($bits >> $i) & 1);
        }

        $modules[7][8] = (bool) (($bits >> 6) & 1);
        $modules[8][8] = (bool) (($bits >> 7) & 1);
        $modules[8][7] = (bool) (($bits >> 8) & 1);

        for ($i = 9; $i < 15; $i++) {
            $modules[8][14 - $i] = (bool) (($bits >> $i) & 1);
        }

        // The second, split between the other two, so the format survives
        // damage to any one corner.
        for ($i = 0; $i < 8; $i++) {
            $modules[8][$size - 1 - $i] = (bool) (($bits >> $i) & 1);
        }

        for ($i = 8; $i < 15; $i++) {
            $modules[$size - 15 + $i][8] = (bool) (($bits >> $i) & 1);
        }

        $modules[$size - 8][8] = true;
    }

    /**
     * How ugly a masked matrix is, by the standard's four rules: long runs,
     * solid blocks, anything shaped like a finder, and too much dark overall.
     */
    private function penalty(array $modules): int
    {
        $size = count($modules);
        $score = 0;
        $dark = 0;

        // Runs of five or more, in both directions, and the finder-alike.
        foreach ([false, true] as $transposed) {
            for ($i = 0; $i < $size; $i++) {
                $run = 1;
                $history = [];

                for ($j = 0; $j < $size; $j++) {
                    $current = $transposed ? $modules[$j][$i] : $modules[$i][$j];
                    $previous = $j > 0
                        ? ($transposed ? $modules[$j - 1][$i] : $modules[$i][$j - 1])
                        : null;

                    if ($j > 0 && $current === $previous) {
                        $run++;
                    } else {
                        if ($j > 0) {
                            $history[] = [$previous, $run];
                        }

                        $run = 1;
                    }

                    if (! $transposed && $current) {
                        $dark++;
                    }
                }

                $history[] = [$transposed ? $modules[$size - 1][$i] : $modules[$i][$size - 1], $run];

                foreach ($history as [, $length]) {
                    if ($length >= 5) {
                        $score += 3 + ($length - 5);
                    }
                }

                // 1:1:3:1:1 with four light modules on one side of it.
                $line = '';

                for ($j = 0; $j < $size; $j++) {
                    $line .= ($transposed ? $modules[$j][$i] : $modules[$i][$j]) ? '1' : '0';
                }

                foreach (['10111010000', '00001011101'] as $pattern) {
                    $score += 40 * substr_count($line, $pattern);
                }
            }
        }

        // Two by two of one colour.
        for ($row = 0; $row < $size - 1; $row++) {
            for ($column = 0; $column < $size - 1; $column++) {
                $first = $modules[$row][$column];

                if ($first === $modules[$row][$column + 1]
                    && $first === $modules[$row + 1][$column]
                    && $first === $modules[$row + 1][$column + 1]) {
                    $score += 3;
                }
            }
        }

        // How far from half the code is dark, in steps of five per cent.
        $percent = $dark * 100 / ($size * $size);
        $score += (int) (floor(abs($percent - 50) / 5) * 10);

        return $score;
    }
}
