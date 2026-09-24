<?php

namespace App\Services;

/**
 * docs/03-rules.md §2 "Dependency".
 *
 * These two flags are derived, never typed in free-hand, so that "unemployed"
 * can never quietly become "unable to earn".
 */
class DependencyRules
{
    /** Under 18, a full-time student under 24 with no income, or 65+ with no work income. */
    public static function isDependent(int $age, bool $isStudent, bool $hasOwnIncome): bool
    {
        if ($age < 18) {
            return true;
        }

        if ($isStudent && $age < 24 && ! $hasOwnIncome) {
            return true;
        }

        return $age >= 65 && ! $hasOwnIncome;
    }

    /**
     * Only a documented condition preventing regular work counts.
     * Unemployment alone is never inability.
     */
    public static function isUnableToEarn(bool $hasDocumentedCondition): bool
    {
        return $hasDocumentedCondition;
    }

    /** The kinship value that carries its own money class. */
    public const SPOUSE = 'spouse';

    /**
     * adult | wife | child | elderly — every member falls under exactly one.
     *
     * The association approved the wife as a money class of its own on
     * 24 Sep 2026. Age still decides the two objective boundaries: a spouse
     * under 18 is a child and one of 65 or over is elderly, because those
     * classes describe the person rather than the marriage. Between them, a
     * spouse is a wife. That precedence is an assumption — the decision sheet
     * does not say which class an elderly spouse falls under — and it is
     * flagged for the association rather than buried.
     */
    public static function personClass(int $age, ?string $relation = null): string
    {
        return match (true) {
            $age < 18 => 'child',
            $age >= 65 => 'elderly',
            $relation === self::SPOUSE => 'wife',
            default => 'adult',
        };
    }
}
