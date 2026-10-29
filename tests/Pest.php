<?php

use App\Http\Middleware\RequireTwoFactor;
use App\Http\Resources\MaskedCaseResource;
use App\Models\Beneficiary;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\HouseholdMember;
use App\Models\Housing;
use App\Models\Region;
use App\Models\RegionRate;
use App\Models\RegionRentReference;
use App\Models\User;
use App\Services\AssessmentService;
use App\Services\BasketService;
use App\Services\DonationService;
use Database\Seeders\FundSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Forms\Components\Wizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
 | A test that moves the clock must not leave it moved. Resetting at the end of
 | the test body is not enough: a failed assertion throws before reaching it,
 | and every test after it in the process then runs at the wrong date — which
 | is how a privacy test started failing about once in five runs, its case
 | having aged out of the funding list.
 */
pest()->afterEach(function () {
    Carbon::setTestNow();
})->in('Feature', 'Unit');

/*
 | Helpers shared by the tests listed in docs/06-tests.md.
 */

function seedCore(): void
{
    (new RoleAndPermissionSeeder)->run();
    (new FundSeeder)->run();
    (new SettingSeeder)->run();
}

/** A region with adult/child/elderly rates and a rent reference in force. */
function regionWithRates(
    int $adult = 5000,
    int $child = 2000,
    int $elderly = 6000,
    int $rent = 40000,
    int $wife = 4000,
): Region {
    $region = Region::factory()->governorate()->create();

    foreach (['adult' => $adult, 'wife' => $wife, 'child' => $child, 'elderly' => $elderly] as $class => $amount) {
        RegionRate::factory()->create([
            'region_id' => $region->id,
            'person_class' => $class,
            'amount' => $amount,
        ]);
    }

    foreach (['1-3', '4-6', '7+'] as $band) {
        RegionRentReference::factory()->create([
            'region_id' => $region->id,
            'family_size_band' => $band,
            'reference_rent' => $rent,
        ]);
    }

    return $region;
}

/** A family of `$adults` adults and `$children` children, owning their home. */
function familyOf(Region $region, int $adults = 2, int $children = 3, array $attributes = []): Beneficiary
{
    $beneficiary = Beneficiary::factory()->create(
        array_merge(['region_id' => placeIn($region, 'village')->id], $attributes)
    );

    HouseholdMember::factory()->count($adults)->adult()->create(['beneficiary_id' => $beneficiary->id]);
    HouseholdMember::factory()->count($children)->child()->create(['beneficiary_id' => $beneficiary->id]);
    Housing::factory()->create(['beneficiary_id' => $beneficiary->id]);

    return $beneficiary->refresh();
}

/*
 | T-74 — a family lives in a village, and a scoped account sits at the level
 | its role allows: a delegate in a village, a supervisor in the area above it.
 |
 | The fixtures used to put both straight into whatever region the test made,
 | usually a governorate. That was never how the association works, and since
 | the level became part of the account the model refuses it. So one helper
 | places a node of the wanted level inside a region, building the chain down
 | to it once and answering from memory afterwards — reference values resolve
 | up the lineage, so a village inherits the rates its governorate carries.
 */
function placeIn(Region $region, string $level): Region
{
    static $cache = [];

    $key = $region->id.':'.$level;

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    if ($region->type === $level) {
        return $cache[$key] = $region;
    }

    $order = ['governorate', 'area', 'village'];
    $from = array_search($region->type, $order, true);
    $to = array_search($level, $order, true);

    // A level above the region given: walk up to it instead.
    if ($from === false || $to === false || $to < $from) {
        return $cache[$key] = $region->ancestorOfType($level) ?? $region;
    }

    $node = $region;

    foreach (array_slice($order, $from + 1, $to - $from) as $type) {
        $node = Region::firstOrCreate(
            ['parent_id' => $node->id, 'type' => $type],
            ['name_ar' => __('sanabel.region_type.'.$type).' '.fake()->unique()->numberBetween(1, 999999), 'is_active' => true],
        );
    }

    return $cache[$key] = $node;
}

function userWithRole(string $roleKey, array $attributes = []): User
{
    $levels = User::ROLE_REGION_LEVEL[$roleKey] ?? null;

    if ($levels && ! empty($attributes['region_id'])) {
        $region = Region::find($attributes['region_id']);

        if ($region && ! in_array($region->type, $levels, true)) {
            // The narrowest level the role may hold, so a delegate lands in a
            // village rather than in the city above it.
            $attributes['region_id'] = placeIn($region, end($levels))->id;
        }
    }

    return User::factory()->role($roleKey)->create($attributes);
}

/** Publishes a family with an approved assessment, so it can be funded. */
function publishedCase(Region $region, int $adults = 2, int $children = 3, array $attributes = []): Beneficiary
{
    $beneficiary = familyOf($region, $adults, $children, array_merge([
        'status' => 'published',
        'approved_at' => now()->subDays(10),
        'published_at' => now()->subDays(10),
    ], $attributes));

    app(AssessmentService::class)->create($beneficiary, status: 'approved');

    return $beneficiary->refresh();
}

/**
 * Clears the second-factor gate for the current test. admin and council cannot
 * reach a panel without it (T-38); SecurityTest covers the gate itself, so
 * panel tests declare that it has already been passed.
 */
function passTwoFactor(): void
{
    session([RequireTwoFactor::SESSION_KEY => now()->toIso8601String()]);
}

/**
 * T-114 — runs a closure with environment variables set, and puts the
 * environment back afterwards.
 *
 * `env()` reads the process environment rather than the config, which is the
 * whole point of the variables being tested here: they are read at the moment
 * a seeder or a command runs, not from a cached config file. So the test has
 * to set them the same way a deployment does. A null value removes one.
 *
 * @param  array<string,string|null>  $variables
 */
function withEnv(array $variables, callable $body): mixed
{
    $previous = [];

    foreach ($variables as $key => $value) {
        $previous[$key] = getenv($key) === false ? null : getenv($key);

        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            continue;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    try {
        return $body();
    } finally {
        foreach ($previous as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                continue;
            }

            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }
}

/**
 * T-114 — runs a console command now, rather than whenever PHP gets round to
 * it.
 *
 * `$this->artisan()` returns a PendingCommand that executes in its
 * destructor, so a command written inside a closure can run after the
 * closure has returned. Paired with withEnv() above, that means the command
 * executes once the environment has already been put back — and a test of
 * what a command reads from the environment silently tests nothing.
 */
function runArtisan(string $command, array $arguments = [], bool $failing = false): int
{
    $pending = test()->artisan($command, $arguments);

    $failing ? $pending->assertFailed() : $pending->assertSuccessful();

    return $pending->run();
}

/**
 * The toggles a household-member row carries in the browser.
 *
 * Filament gives every Toggle a default of false, so a row added on screen
 * sends false. fillForm() replaces the state wholesale and the defaults go
 * with it, so a test row states them.
 *
 * @return array<string,bool>
 */
/**
 * The wizard on the case form, found rather than counted to.
 *
 * T-159 put a line above it saying who wrote to the file last, so the wizard
 * is no longer the first component and a test that assumes it is breaks on
 * the next thing added over it. T-177 gave the read-only screen its own
 * answer about skipping, so two files ask for it now and it lives here.
 */
function wizardOf($page): Wizard
{
    return collect($page->instance()->form->getComponents())
        ->first(fn ($component) => $component instanceof Wizard);
}

function memberToggles(): array
{
    return [
        'smokes' => false,
        'is_quran_memorizer' => false,
        'has_documented_condition' => false,
        'has_certificate' => false,
        'can_teach_quran' => false,
        'is_orphan' => false,
        // T-176 — asked of every member now, so a fixture answers it.
        'has_chronic_illness' => false,
    ];
}

/** One household-member row as the form would send it.
 *
 * @param  array<string,mixed>  $overrides
 * @return array<string,mixed>
 */
function memberRow(array $overrides = []): array
{
    return array_merge([
        'name_ar' => 'فرد',
        'relation' => 'brother',
        'gender' => 'male',
        'birth_year' => 1990,
        'person_class' => 'adult',
    ], memberToggles(), $overrides);
}

/**
 * T-156 — the three yes/no questions in front of the income, health and asset
 * tables. A file saved through the form answers them, so a fixture does too.
 *
 * @return array<string,string>
 */
function gatesAnsweredNo(): array
{
    return ['has_income' => 'no', 'has_health' => 'no', 'has_assets' => 'no'];
}

/**
 * The masked payload for a family, as a donor receives it.
 *
 * @return array<string,mixed>
 */
function maskedOf(Beneficiary $case): array
{
    return (new MaskedCaseResource($case->fresh()))->resolve();
}

/**
 * Verified money against one family's month, as a donor's basket would put it
 * there: a hold, a recorded transfer, and the finance desk's verification.
 */
function fundFor(Beneficiary $case, int $amount, ?Carbon $month = null): Donation
{
    $donor = Donor::factory()->create();
    $baskets = app(BasketService::class);
    $basket = $baskets->openFor($donor);

    $baskets->addItem($basket, $case, $amount, $month);
    $baskets->reserve($basket);

    $donation = app(DonationService::class)->record([
        'donor_id' => $donor->id,
        'basket_id' => $basket->id,
        'amount' => $amount,
        'transaction_ref' => 'FUND-'.uniqid(),
    ]);

    return app(DonationService::class)->verify($donation, userWithRole('admin')->id);
}

/**
 * T-195 — every chained statement in `$source` that opens with `$opening`,
 * as [offset, text] pairs.
 *
 * A fluent Filament chain ends at the comma that separates it from the next
 * field in the array, or at the semicolon that ends the statement — whichever
 * comes first at depth zero.
 *
 * @return array<int,array{0:int,1:string}>
 */
function statementsOpenedBy(string $source, string $opening): array
{
    $found = [];
    $offset = 0;

    while (($start = strpos($source, $opening, $offset)) !== false) {
        $depth = 0;
        $quote = null;
        $i = $start + strlen($opening) - 1;

        for (; $i < strlen($source); $i++) {
            $c = $source[$i];

            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($c === "'" || $c === '"') {
                $quote = $c;
            } elseif ($c === '(' || $c === '[') {
                $depth++;
            } elseif ($c === ')' || $c === ']') {
                if (--$depth < 0) {
                    break;
                }
            } elseif ($depth === 0 && ($c === ',' || $c === ';')) {
                break;
            }
        }

        $found[] = [$start, substr($source, $start, $i - $start)];
        $offset = $i;
    }

    return $found;
}
