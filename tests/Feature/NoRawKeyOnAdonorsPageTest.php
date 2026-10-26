<?php

use App\Models\HealthRecord;

/*
 | T-165 — found while photographing the case page for the report: the housing
 | row read «sanabel.housing_type.rented».
 |
 | Every donor-facing label on that page is a key looked up in lang/ar, and a
 | key that is not in the list comes back as the key itself. So a value that
 | reached the column from an import, from an older list, or from a hand at a
 | console prints the name of a translation file on the open internet. It says
 | nothing now, and the row is simply not drawn.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('draws no row at all for a housing type nobody has named', function () {
    $case = publishedCase($this->region);

    $case->housing()->updateOrCreate(
        ['beneficiary_id' => $case->id],
        ['housing_type' => 'rented_but_not_on_the_list'],
    );

    expect(maskedOf($case)['housing_type_label'])->toBeNull();

    $this->get(route('opportunity', $case->file_number))
        ->assertOk()
        ->assertDontSee('sanabel.housing_type');
});

it('keeps the labels that are on the list', function () {
    $case = publishedCase($this->region);

    $case->housing()->updateOrCreate(['beneficiary_id' => $case->id], ['housing_type' => 'rent']);

    expect(maskedOf($case)['housing_type_label'])->toBe(__('sanabel.housing_type.rent'));
});

it('leaves an unknown illness, kinship, stage or status off the page too', function () {
    $case = publishedCase($this->region, adults: 1, children: 0);

    HealthRecord::factory()->create([
        'beneficiary_id' => $case->id,
        'condition' => 'a_condition_nobody_added',
        'severity_band' => 50,
    ]);

    $member = $case->members()->first();
    $member?->forceFill([
        'relation' => 'a_relation_nobody_added',
        'school_stage' => 'a_stage_nobody_added',
        'occupation' => 'an_occupation_nobody_added',
        'dependent' => true,
    ])->save();

    $payload = json_encode(maskedOf($case->fresh()), JSON_UNESCAPED_UNICODE);

    expect($payload)->not->toContain('sanabel.health.conditions')
        ->not->toContain('sanabel.relation')
        ->not->toContain('sanabel.school_stage')
        ->not->toContain('sanabel.occupation');

    $this->get(route('opportunity', $case->file_number))
        ->assertOk()
        ->assertDontSee('sanabel.');
});
