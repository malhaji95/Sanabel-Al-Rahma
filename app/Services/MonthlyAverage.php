<?php

namespace App\Services;

/**
 * T-303 — «تحويل الدخل السنوي المنتظم إلى متوسط شهري بقسمته على 12»,
 * 12 November.
 *
 * One rule, in one place, because four kinds of money now answer to it: a
 * row in the income table, a steady income from animals, the rent of a
 * property or a vehicle, and a standing arrangement with neighbours or
 * another association.
 *
 * T-287 wrote it once for the income rows. Writing it a second, third and
 * fourth time is how «سنوي ÷ ١٢» ends up meaning three different things in
 * one system, so §3's three concrete cases are here and this is the
 * abstraction they permit.
 *
 * The rule, as the association stated it:
 *
 *   steady    — the amount is what it puts into a month;
 *   seasonal  — the amount, times the number of times a year it arrives,
 *               over twelve;
 *   neither   — nothing, documented. A sum nobody could place in a year is
 *               not turned into a monthly figure by guessing.
 */
final class MonthlyAverage
{
    public static function of(?bool $isStable, int $amount, ?int $timesPerYear): int
    {
        if ($amount <= 0) {
            return 0;
        }

        if ($isStable === true) {
            return $amount;
        }

        // Unanswered is not «no», and neither is counted: both leave the
        // figure unplaced, and an unplaced figure contributes nothing.
        if (($timesPerYear ?? 0) < 1) {
            return 0;
        }

        return (int) floor($amount * $timesPerYear / 12);
    }
}
