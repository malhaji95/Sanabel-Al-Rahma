<?php

namespace App\Services;

use App\Http\Resources\MaskedCaseResource;
use App\Models\Beneficiary;
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
        bool $zakatOnly = false,
        // T-208, T-228 — any of the association's classifications; empty for
        // all of them.
        array $kinds = [],
    ): array {
        $seconds = (int) config('sanabel.public_list_cache_seconds');

        $kinds = $this->allowedKinds($kinds);

        $build = fn () => $this->build($supportType, $regionId, $page, $perPage, $withCovered, $zakatOnly, $kinds);

        if ($seconds <= 0) {
            return $build();
        }

        return Cache::remember(
            $this->key($supportType, $regionId, $page, $perPage, $withCovered, $zakatOnly, $kinds),
            $seconds,
            $build,
        );
    }

    /**
     * The classifications a donor may narrow the list by, and their words.
     *
     * T-208 — data, not code: the list is the classifications actually
     * written on published files in the asker's own scope, so a door a donor
     * is shown always has somebody behind it, and a door the association
     * stops using disappears on its own.
     *
     * Behind the same switch as the words on the card. Rule 2 is why: to
     * filter by «يتيم» and be shown a family is to be told that family is an
     * orphan file, so a filter publishes the classification exactly as the
     * badge does. The association closes one and both close.
     *
     * @return array<string,string>
     */
    public function kinds(): array
    {
        if (! Setting::value('show_sponsorship_kind', config('sanabel.setting_defaults.show_sponsorship_kind'))) {
            return [];
        }

        $seconds = (int) config('sanabel.public_list_cache_seconds');

        $build = fn () => $this->ranking->publishedKinds();

        $present = $seconds <= 0
            ? $build()
            : Cache::remember(
                implode(':', ['sanabel.cases.kinds', Setting::fingerprint(), $this->scope()]),
                $seconds,
                $build,
            );

        // The association's own order, so the chips do not shuffle between
        // one page and the next.
        return collect(Beneficiary::CASE_CLASSIFICATIONS)
            ->filter(fn (string $key) => in_array($key, $present, true))
            // T-217 — the door's own name, not the person's attribute.
            ->mapWithKeys(fn (string $key) => [$key => __('sanabel.sponsorship_kind.'.$key)])
            ->all();
    }

    /**
     * The sponsorship doors, and their words.
     *
     * T-228 — «في صفحة الكفالات لازم يظهر كل كفاله، ايش نوعها يتيم ارمله
     * طالب», 1 November. Of the eight classifications a file may carry,
     * these are the ones the association called kinds of sponsorship; the
     * rest are grounds for a payment rather than a door a donor walks
     * through. The list is config, and a door still has to have somebody
     * behind it before it is drawn.
     *
     * @return array<string,string>
     */
    public function sponsorshipDoors(): array
    {
        $declared = (array) config('sanabel.sponsorship_kinds');

        return collect($this->kinds())
            ->filter(fn (string $label, string $key) => in_array($key, $declared, true))
            ->all();
    }

    /** The keys the association has declared, and nothing else. */
    private function allowedKinds(array $kinds): array
    {
        $offered = $this->kinds();

        return collect($kinds)
            ->filter(fn ($key) => is_string($key) && array_key_exists($key, $offered))
            ->unique()
            ->values()
            ->all();
    }

    /** @return array{cards:array<int,array>,total:int} */
    private function build(
        string $supportType,
        ?int $regionId,
        int $page,
        int $perPage,
        bool $withCovered,
        bool $zakatOnly,
        array $kinds = [],
    ): array {
        /*
         | T-189 — «الفلاتر: جميع الحالات, دعم شهري, دعم لمرة واحدة»,
         | 29 October. The ranking has always taken null for «both kinds»;
         | what was missing was a word for it on the screen, and a word the
         | cache key and the query string can carry.
         */
        $ranked = $this->ranking->fundingList(
            $supportType === 'all' ? null : $supportType,
            $regionId,
            $withCovered,
            $zakatOnly,
            $kinds,
        );

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

    private function key(
        string $supportType,
        ?int $regionId,
        int $page,
        int $perPage,
        bool $withCovered,
        bool $zakatOnly,
        array $kinds = [],
    ): string {
        return implode(':', [
            'sanabel.cases',
            Setting::fingerprint(),
            $this->scope(),
            $supportType,
            $regionId ?? 'all',
            $page,
            $perPage,
            $withCovered ? 'with-covered' : 'open-only',
            // T-98 — a filtered list is a different list, so it is a different
            // key. Without this the zakat list would be served the general one.
            $zakatOnly ? 'zakat' : 'any',
            // T-208, T-228 — likewise: a list narrowed to a set of doors
            // is its own list, and the order of the set must not make two
            // keys out of one question.
            $kinds === [] ? 'any-kind' : implode(',', collect($kinds)->sort()->values()->all()),
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
