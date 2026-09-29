<?php

namespace App\Services;

use App\Models\Setting;

/**
 * One destination, settled on 29 Sep 2026: Sanabel Al-Rahma's own Sham Cash
 * wallet. Every transfer lands there — the donor either names the file the
 * money answers for, or leaves it general — and Sanabel Al-Rahma moves it on
 * to the family, the hospital, the creditor, or wherever the need is.
 *
 * There is nothing per family and nothing per partner to resolve, so this is
 * one lookup rather than a routing table.
 */
class TransferRouting
{
    /** Read once per request; a page may ask for it in more than one place. */
    private ?array $wallet = null;

    /**
     * The wallet a donor transfers to, or null until the association enters
     * one — in which case the screens say so rather than showing a blank.
     *
     * @return array{wallet:string,holder:string}|null
     */
    public function wallet(): ?array
    {
        $stored = $this->wallet ??= (array) Setting::value('platform_wallet', []);
        $number = trim((string) ($stored['number'] ?? ''));

        if ($number === '') {
            return null;
        }

        return [
            'wallet' => $number,
            'holder' => trim((string) ($stored['holder'] ?? '')) ?: config('brand.name'),
        ];
    }
}
