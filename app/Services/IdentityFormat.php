<?php

namespace App\Services;

use Closure;

/**
 * T-121 — how long an identity number and a telephone number are, in one
 * place, because the association asked for both «في كل شي في الموقع».
 *
 * «عند ادخال الرقم الوطني 11 الرقم يتوقف, يعني لا يقبل اكثر او اقل» and «عند
 * ادخال رقم الهاتف … موجود ارقام التي هي 10 ارقام», 25 October.
 *
 * Two lengths, five screens and a public form between them, which is what a
 * shared rule is for: a number typed into the household file, the landlord's,
 * the doctor's, the creditor's and a donor's all answers to the same one.
 *
 * It also accepts Arabic-Indic digits. A researcher filling the form on an
 * Arabic keyboard types ٠٩٣٣, and a field that refuses it is a field that
 * reads as broken rather than as strict.
 */
final class IdentityFormat
{
    public const NATIONAL_ID_DIGITS = 11;

    public const PHONE_DIGITS = 10;

    /** ٠١٢٣٤٥٦٧٨٩ and ۰۱۲۳۴۵۶۷۸۹, which phone keyboards send. */
    private const ARABIC_DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /**
     * A rule that counts the digits rather than the characters.
     *
     * `digits:11` would do, if people typed only ASCII and never a space.
     * They type ٠٩٣٣ and they type «0933 123 456», and both are the number
     * they mean — so the value is read as digits first and measured after,
     * exactly as it will be stored.
     */
    public static function lengthRule(int $digits, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($digits, $message) {
            if (blank($value)) {
                return;
            }

            if (strlen((string) self::digits((string) $value)) !== $digits) {
                $fail($message);
            }
        };
    }

    /**
     * The digits of a number as typed: Arabic-Indic read as the digits they
     * are, and the spaces, dashes and brackets people put in a telephone
     * number dropped.
     */
    public static function digits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtr($value, self::ARABIC_DIGITS);

        $digits = preg_replace('/\D+/u', '', $value);

        return $digits === '' ? null : $digits;
    }
}
