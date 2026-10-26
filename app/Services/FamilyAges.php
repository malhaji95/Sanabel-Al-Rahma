<?php

namespace App\Services;

/**
 * T-153 — ages inside one household that cannot be true at once.
 *
 * «مثلا سجلنا الابن مواليد 1996 والام 1997 لازم النظام يرفض هيك حالات لأنه
 * الابن هو اصغر من الاب والام», 29 October.
 *
 * The form took it, the assessment priced it, and nobody reading the file
 * afterwards could tell a typed year from a real one. These are the two
 * directions kinship fixes on its own: a child is born after the head of the
 * household, and a parent before. Every other kinship — a brother, a sister,
 * «أخرى» — says nothing about who is older, so nothing is refused.
 */
class FamilyAges
{
    /** Kinships whose member must have been born after the head. */
    public const BORN_AFTER_HEAD = ['son', 'daughter'];

    /** Kinships whose member must have been born before the head. */
    public const BORN_BEFORE_HEAD = ['father', 'mother'];

    /**
     * Whether a member's year of birth can be true beside the head's.
     *
     * Unanswered is not refused: a file is filled over weeks and half a
     * household is a normal state for it to be in.
     */
    public static function birthYearFits(?string $relation, ?int $memberYear, ?int $headYear): bool
    {
        if (! $relation || ! $memberYear || ! $headYear) {
            return true;
        }

        if (in_array($relation, self::BORN_AFTER_HEAD, true)) {
            return $memberYear > $headYear;
        }

        if (in_array($relation, self::BORN_BEFORE_HEAD, true)) {
            return $memberYear < $headYear;
        }

        return true;
    }

    /**
     * Whether «يتيم» is a question worth asking about this member.
     *
     * «في حال كان عمره اكبر من العمر المفروض لخيار يتيم لازم يختفي خيار يتيم
     * بحيث تحت 15 يعني شرط تفعيل اليتيم تكون الام ارملة وعمره تحت 15».
     *
     * Two conditions, both the association's: the household is headed by a
     * widow or a widower, and the child is under the age at which this form
     * starts treating somebody as an adult — the same fifteen that decides
     * whether work or marriage may be answered at all.
     */
    public static function orphanMayBeAsked(?int $age, ?string $headMaritalStatus): bool
    {
        return $headMaritalStatus === 'widowed'
            && $age !== null
            && $age < DependencyRules::ANSWERS_AS_ADULT_FROM;
    }
}
