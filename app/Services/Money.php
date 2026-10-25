<?php

namespace App\Services;

/**
 * T-136 — «استبدل SYP بـ نحتاج نكتبها بالعربي جنب المبلغ (ليرة سورية جديدة)
 * لاننا نخاف يجيي ياكد الطلب بباله قديمه ويسدد جديدة, هون مشكلة هيك»,
 * 28 October.
 *
 * The code and the word are two different things. `SYP` is what the
 * `currency` column holds — rule 9 keeps money as an integer beside a
 * currency code, and every row written since the first day says `SYP`, so
 * the stored value does not move. What a donor reads does: a man who has
 * been told «٣٢٦٥٦ ل.س» in the old lira all his life, and transfers in the
 * old lira, has sent a hundred times too little.
 *
 * So one place that turns a stored code into the words beside an amount: the
 * full name where somebody is about to pay, the short one where a table
 * column has no room for it. An unknown code is printed as it is rather than
 * guessed at — the association uses one currency, and the day it uses two,
 * the second one shows its own code until it is named here.
 */
class Money
{
    /** «ليرة سورية جديدة» — where the amount is about to be transferred. */
    public static function label(?string $code = null): string
    {
        return self::word($code, 'label');
    }

    /** «ل.س.ج» — where a column or a suffix has room for nothing longer. */
    public static function short(?string $code = null): string
    {
        return self::word($code, 'short');
    }

    private static function word(?string $code, string $form): string
    {
        $code = $code ?: config('sanabel.currency');
        $key = 'sanabel.currency.'.$code.'.'.$form;

        return __($key) === $key ? (string) $code : __($key);
    }
}
