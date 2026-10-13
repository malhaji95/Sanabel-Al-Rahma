<?php

namespace App\Services;

use App\Http\Resources\MaskedCaseResource;
use App\Models\Scopes\RegionScope;
use App\Models\Setting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * T-87 — one page of the donor's list of families, and the cache in front of it.
 *
 * Three screens draw the same thing: the browse page, the donor API, and the
 * three families on the home page. Each was ranking every published family,
 * then loading a page of them, then masking each one — on every request, for
 * every visitor, with nothing held between them. A link shared on WhatsApp is
 * the load this platform will actually meet, and it arrives all at once.
 *
 * So the resolved cards are held for a minute. What is cached is data, never a
 * rendered page: the page is drawn fresh every time, with its own session and
 * its own CSRF token, and only the figures on it are shared.
 *
 * Three things make a card what it is, and all three are in the key:
 *
 *   - the list asked for — kind of support, region, page;
 *   - the settings, by the token that changes whenever one does, so a switch
 *     the association closes takes effect at once rather than in a minute;
 *   - who is asking, but only insofar as it changes what they may see. A
 *     delegate and a supervisor are held to their own region by a global
 *     scope, so their lists are cached apart from everybody else's. A donor
 *     and a visitor see the same list, and share it.
 *
 * What it does not answer for is money arriving: a verified donation shows on
 * the list within the minute. Money is moved and verified by hand here, so a
 * minute is not a figure anybody is reading against a clock.
 */
class DonorCaseList
{
    public function __construct(private readonly RankingService $ranking) {}

    /**
     * @return array{cards:array<int,array>,total:int}
     */
    public function page(
        string $supportType,
        ?int $regionId,
        int $page,
        int $perPage,
        bool $withCovered = true,
    ): array {
        $seconds = (int) config('sanabel.public_list_cache_seconds');

        $build = fn () => $this->build($supportType, $regionId, $page, $perPage, $withCovered);

        if ($seconds <= 0) {
            return $build();
        }

        return Cache::remember(
            $this->key($supportType, $regionId, $page, $perPage, $withCovered),
            $seconds,
            $build,
        );
    }

    /** @return array{cards:array<int,array>,total:int} */
    private function build(
        string $supportType,
        ?int $regionId,
        int $page,
        int $perPage,
        bool $withCovered,
    ): array {
        $ranked = $this->ranking->fundingList($supportType, $regionId, $withCovered);

        return [
            'cards' => $this->ranking
                ->hydrate($ranked->forPage($page, $perPage))
                ->map(fn (array $row) => (new MaskedCaseResource(
                    $row['beneficiary'],
                    $row['confirmed'],
                    $row['month'],
                ))->resolve())
                ->all(),
            'total' => $ranked->count(),
        ];
    }

    private function key(string $supportType, ?int $regionId, int $page, int $perPage, bool $withCovered): string
    {
        return implode(':', [
            'sanabel.cases',
            Setting::fingerprint(),
            $this->scope(),
            $supportType,
            $regionId ?? 'all',
            $page,
            $perPage,
            $withCovered ? 'with-covered' : 'open-only',
        ]);
    }

    /**
     * What the asker is allowed to see, and nothing else about them.
     *
     * RegionScope holds three roles inside their own subtree. Everyone else —
     * a visitor, a donor, the office — sees the same list and shares one entry.
     */
    private function scope(): string
    {
        $user = Auth::user();

        return $user && in_array($user->role?->key, RegionScope::SCOPED_ROLES, true) && $user->region_id
            ? 'region-'.$user->region_id
            : 'all';
    }
}
