<?php

namespace App\Services;

use App\Models\Basket;
use App\Models\BasketItem;
use App\Models\Beneficiary;
use App\Models\Setting;

/**
 * Where a donor sends the money — settled on 29 Sep 2026.
 *
 * Never to the family. A donor transfers to the association the family belongs
 * to, or, when it belongs to none, to the platform's own wallet. That is the
 * whole of it: there is no choice to make and no family wallet to show, so a
 * donor sees a destination that is never a household's own account.
 */
class TransferRouting
{
    /*
     | Read once per request rather than once per card: a donor list resolves a
     | destination for every family on the page.
     */
    private ?array $platformWallet = null;

    /**
     * The one destination for this family: its association's wallet, or the
     * platform's when it has no association or the association entered none.
     *
     * @return array<int,array{route:string,wallet:string,holder:string}>
     */
    public function routesFor(Beneficiary $case): array
    {
        $association = $case->association;

        if ($association && ($wallet = $this->clean($association->wallet_encrypted))) {
            return [[
                'route' => 'association',
                'wallet' => $wallet,
                'holder' => $association->name,
            ]];
        }

        return array_filter([$this->platformRoute()]);
    }

    /**
     * The destinations a whole basket needs, de-duplicated by wallet, so two
     * families of one association show one number with both file numbers
     * beside it. A campaign follows its own wallet when it has one.
     *
     * @return array<int,array{route:string,wallet:string,holder:string,files:array<int,string>}>
     */
    public function routesForBasket(Basket $basket): array
    {
        $routes = [];

        foreach ($basket->items()->with(['beneficiary.association', 'campaign'])->get() as $item) {
            $label = $item->campaign?->title_ar ?? $item->beneficiary?->file_number;

            foreach ($this->routesForItem($item) as $route) {
                $key = $route['route'].'|'.$route['wallet'];
                $routes[$key] ??= $route + ['files' => []];

                if ($label && ! in_array($label, $routes[$key]['files'], true)) {
                    $routes[$key]['files'][] = $label;
                }
            }
        }

        return array_values($routes);
    }

    /** @return array<int,array{route:string,wallet:string,holder:string}> */
    private function routesForItem(BasketItem $item): array
    {
        if ($campaign = $item->campaign) {
            $wallet = $this->clean($campaign->wallet_encrypted);

            return $wallet
                ? [['route' => 'association', 'wallet' => $wallet, 'holder' => $campaign->title_ar]]
                : array_filter([$this->platformRoute()]);
        }

        return $item->beneficiary ? $this->routesFor($item->beneficiary) : [];
    }

    /** The association's own wallet — also the destination for general money. */
    public function platformRoute(): ?array
    {
        $wallet = $this->platformWallet ??= (array) Setting::value('platform_wallet', []);

        if (! $this->clean($wallet['number'] ?? null)) {
            return null;
        }

        return [
            'route' => 'platform',
            'wallet' => $this->clean($wallet['number']),
            'holder' => (string) ($wallet['holder'] ?? config('brand.name')),
        ];
    }

    private function clean(mixed $value): string
    {
        return trim((string) $value);
    }
}
