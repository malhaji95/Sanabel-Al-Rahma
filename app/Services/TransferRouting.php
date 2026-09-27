<?php

namespace App\Services;

use App\Models\Basket;
use App\Models\BasketItem;
use App\Models\Beneficiary;
use App\Models\Setting;

/**
 * Where a donor sends the money, decided on 27 Sep 2026.
 *
 * Three modes: `direct` shows the family's own wallet, `association` shows the
 * wallet of the association the family belongs to, and `both` offers the two
 * and lets the donor choose.
 *
 * The mode is read in order of how specific it is: a family's own setting, then
 * its association's, then the platform default. An empty wallet silently drops
 * its option rather than showing a route nobody can pay into — and if that
 * leaves nothing, the platform wallet answers, so a donor is never told to
 * transfer with no number to transfer to.
 */
class TransferRouting
{
    public const MODES = ['direct', 'association', 'both'];

    /*
     | A donor list resolves a route per family, so reading the two settings
     | from the database each time cost a query per card. They are read once
     | per request: the service is a singleton, and a setting edit takes
     | effect on the next request either way.
     */
    private ?string $defaultMode = null;

    private ?array $platformWallet = null;

    /** direct|association|both — never null, so a caller never guesses. */
    public function modeFor(Beneficiary $case): string
    {
        $mode = $case->transfer_mode
            ?: $case->association?->transfer_mode
            ?: $this->defaultMode();

        return in_array($mode, self::MODES, true) ? $mode : 'association';
    }

    /**
     * The routes to put in front of the donor, most specific first.
     *
     * Each is ['route' => direct|association|platform, 'wallet' => string,
     * 'holder' => string]. The holder is the association's name or the
     * platform's — never the family's, which stays masked even when its
     * wallet is shown.
     *
     * @return array<int,array{route:string,wallet:string,holder:string}>
     */
    public function routesFor(Beneficiary $case): array
    {
        $mode = $this->modeFor($case);
        $routes = [];

        if ($mode === 'direct' || $mode === 'both') {
            if ($wallet = $this->clean($case->wallet_encrypted)) {
                $routes[] = [
                    'route' => 'direct',
                    'wallet' => $wallet,
                    // The file number, deliberately: the donor already has it,
                    // and the family's name is not theirs to learn.
                    'holder' => $case->file_number,
                ];
            }
        }

        if ($mode === 'association' || $mode === 'both') {
            $association = $case->association;

            if ($association && ($wallet = $this->clean($association->wallet_encrypted))) {
                $routes[] = [
                    'route' => 'association',
                    'wallet' => $wallet,
                    'holder' => $association->name,
                ];
            }
        }

        return $routes === [] ? array_filter([$this->platformRoute()]) : $routes;
    }

    /**
     * The routes a whole basket needs, de-duplicated by wallet.
     *
     * Two families of the same association share one wallet, so the donor is
     * shown one number and both file numbers beside it rather than the same
     * number twice. A campaign item follows the campaign's own wallet when it
     * has one, since a campaign is funded as itself.
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

    private function defaultMode(): string
    {
        return $this->defaultMode ??= (string) Setting::value(
            'default_transfer_mode',
            config('sanabel.setting_defaults.default_transfer_mode'),
        );
    }

    /** The platform's own wallet, for a family with no association and no route of its own. */
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
