<?php

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
    (new Database\Seeders\RoleAndPermissionSeeder)->run();
    (new Database\Seeders\FundSeeder)->run();
    (new Database\Seeders\SettingSeeder)->run();
}

/** A region with adult/child/elderly rates and a rent reference in force. */
function regionWithRates(int $adult = 5000, int $child = 2000, int $elderly = 6000, int $rent = 40000): App\Models\Region
{
    $region = App\Models\Region::factory()->governorate()->create();

    foreach (['adult' => $adult, 'child' => $child, 'elderly' => $elderly] as $class => $amount) {
        App\Models\RegionRate::factory()->create([
            'region_id' => $region->id,
            'person_class' => $class,
            'amount' => $amount,
        ]);
    }

    foreach (['1-3', '4-6', '7+'] as $band) {
        App\Models\RegionRentReference::factory()->create([
            'region_id' => $region->id,
            'family_size_band' => $band,
            'reference_rent' => $rent,
        ]);
    }

    return $region;
}

/** A family of `$adults` adults and `$children` children, owning their home. */
function familyOf(App\Models\Region $region, int $adults = 2, int $children = 3, array $attributes = []): App\Models\Beneficiary
{
    $beneficiary = App\Models\Beneficiary::factory()->create(
        array_merge(['region_id' => $region->id], $attributes)
    );

    App\Models\HouseholdMember::factory()->count($adults)->adult()->create(['beneficiary_id' => $beneficiary->id]);
    App\Models\HouseholdMember::factory()->count($children)->child()->create(['beneficiary_id' => $beneficiary->id]);
    App\Models\Housing::factory()->create(['beneficiary_id' => $beneficiary->id]);

    return $beneficiary->refresh();
}

function userWithRole(string $roleKey, array $attributes = []): App\Models\User
{
    return App\Models\User::factory()->role($roleKey)->create($attributes);
}

/** Publishes a family with an approved assessment, so it can be funded. */
function publishedCase(App\Models\Region $region, int $adults = 2, int $children = 3, array $attributes = []): App\Models\Beneficiary
{
    $beneficiary = familyOf($region, $adults, $children, array_merge([
        'status' => 'published',
        'approved_at' => now()->subDays(10),
        'published_at' => now()->subDays(10),
    ], $attributes));

    app(App\Services\AssessmentService::class)->create($beneficiary, status: 'approved');

    return $beneficiary->refresh();
}
