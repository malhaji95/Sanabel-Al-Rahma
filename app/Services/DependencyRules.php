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

    /** adult | child | elderly — every member falls under exactly one. */
    public static function personClass(int $age): string
    {
        return match (true) {
            $age < 18 => 'child',
            $age >= 65 => 'elderly',
            default => 'adult',
        };
    }
}
