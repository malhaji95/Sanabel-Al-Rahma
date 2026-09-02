<?php

use App\Http\Middleware\RequireTwoFactor;
use App\Models\Beneficiary;
use App\Models\HouseholdMember;
use App\Models\Housing;
use App\Models\Region;
use App\Models\RegionRate;
use App\Models\RegionRentReference;
use App\Models\User;
use App\Services\AssessmentService;
use Database\Seeders\FundSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

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
function regionWithRates(int $adult = 5000, int $child = 2000, int $elderly = 6000, int $rent = 40000): Region
{
    $region = Region::factory()->governorate()->create();

    foreach (['adult' => $adult, 'child' => $child, 'elderly' => $elderly] as $class => $amount) {
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
        array_merge(['region_id' => $region->id], $attributes)
    );

    HouseholdMember::factory()->count($adults)->adult()->create(['beneficiary_id' => $beneficiary->id]);
    HouseholdMember::factory()->count($children)->child()->create(['beneficiary_id' => $beneficiary->id]);
    Housing::factory()->create(['beneficiary_id' => $beneficiary->id]);

    return $beneficiary->refresh();
}

function userWithRole(string $roleKey, array $attributes = []): User
{
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
