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

    /**
     * T-121 — «عند وضع عمري 3 سنوات تم وضع اني اعمل وأرمل وتم القبول. هذا
     * الأشياء مفروض يعطي انه في خطأ», 25 October. The form took it, and a file
     * that records a three-year-old widow in work is a file nobody can act on.
     *
     * Fifteen is the association's own line: it is the age the survey already
     * asks every dependant about («العمر أقل من ١٥ سنة»), so the form is not
     * being given a boundary of ours to enforce. What it refuses is only what
     * cannot be true — working, and a marriage ended or in force — and never
     * the age itself: a household headed by a minor exists, and the system has
     * no business telling an orphan they are too young to hold their own file.
     *
     * If the association would rather draw it at another age, it is this one
     * number.
     */
    public const ANSWERS_AS_ADULT_FROM = 15;

    /** True when the person is old enough for the questions that imply an adult. */
    public static function answersAsAdult(?int $age): bool
    {
        // An unknown age is not a contradiction, and the form asks for the age
        // elsewhere. Only what is stated is checked.
        return $age === null || $age >= self::ANSWERS_AS_ADULT_FROM;
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
