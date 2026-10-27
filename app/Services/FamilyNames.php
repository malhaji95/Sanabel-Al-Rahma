<?php

namespace App\Services;

/**
 * T-175 — the names a household shares, and who has to be asked for them.
 *
 * «فقط الأب مطلوب الكنية والابن ياخد كنية الأب, لكن بنفس الوقت يتم طلب كنية
 * الزوجة التي هي الأم, لكن للابن يتم تعبئة اسم الأم مباشر بعد تعبئة معلومات
 * أمه وهكذا», 1 November.
 *
 * A son's surname, his father's name and his mother's name are all already on
 * the file by the time his row is reached: the surname and one parent are the
 * head's own, the other parent is the spouse the head was required to name
 * (T-152). Asking for them again is three chances to spell the family's name
 * three different ways.
 *
 * A wife is the exception their rule names: her surname is her own, so it is
 * asked. So is everything about a dependant who is neither a son nor a
 * daughter — «إذا كان المعال غير الابن والبنت نحتاج اسم الأب والكنية» — because
 * the file knows nothing about whose child they are.
 *
 * Every method answers null when it cannot know, and null means «ask».
 */
class FamilyNames
{
    /** Kinships whose names the file already holds. */
    public const CARRIES_THE_HEADS_NAMES = ['son', 'daughter'];

    public static function inheritsFromHead(?string $relation): bool
    {
        return in_array($relation, self::CARRIES_THE_HEADS_NAMES, true);
    }

    /** The surname a child carries: the head's own, and nobody else's. */
    public static function surnameFor(?string $relation, ?string $headFamilyName): ?string
    {
        return self::inheritsFromHead($relation) ? ($headFamilyName ?: null) : null;
    }

    /**
     * A child's father: the head when the head is a man, and the spouse the
     * head named when the head is a woman.
     *
     * A head whose gender is unanswered is not guessed at — the form asks
     * instead, which is what null means here.
     */
    public static function fatherFor(
        ?string $relation,
        ?string $headGender,
        ?string $headFirstName,
        ?string $spouseName,
    ): ?string {
        return self::parentFor($relation, 'male', $headGender, $headFirstName, $spouseName);
    }

    /** And a child's mother, by the same reading from the other side. */
    public static function motherFor(
        ?string $relation,
        ?string $headGender,
        ?string $headFirstName,
        ?string $spouseName,
    ): ?string {
        return self::parentFor($relation, 'female', $headGender, $headFirstName, $spouseName);
    }

    private static function parentFor(
        ?string $relation,
        string $parentGender,
        ?string $headGender,
        ?string $headFirstName,
        ?string $spouseName,
    ): ?string {
        if (! self::inheritsFromHead($relation) || ! in_array($headGender, ['male', 'female'], true)) {
            return null;
        }

        return ($headGender === $parentGender ? $headFirstName : $spouseName) ?: null;
    }
}
