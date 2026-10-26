<?php

use App\Models\Beneficiary;
use App\Models\Setting;

/*
 | T-161 — «نحتاج نكتب عبارة: تم التأكد من عدم تكرار الرقم الوطني للعائلة»,
 | 30 October.
 |
 | The line is a claim about this file, so it is made only where it is true,
 | and the number it is made about never travels with it.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('writes the line on a file whose number was stored and checked', function () {
    $case = publishedCase($this->region);

    expect(maskedOf($case)['duplicate_checked'])->toBeTrue();

    $this->get(route('opportunity', $case->file_number))
        ->assertOk()
        ->assertSee(__('sanabel.masked.duplicate_checked'));
});

it('never carries the number the check was made on', function () {
    $case = publishedCase($this->region);
    $case->forceFill([
        'national_id_encrypted' => '91234567890',
        'national_id_hash' => Beneficiary::hashNationalId('91234567890'),
    ])->save();

    $payload = json_encode(maskedOf($case), JSON_UNESCAPED_UNICODE);

    expect($payload)->not->toContain('91234567890')
        ->not->toContain($case->fresh()->national_id_hash);
});

it('claims nothing on a file whose personal data has been destroyed', function () {
    $case = publishedCase($this->region);

    // The marker the service sets once the destruction has run. What the
    // row still holds does not matter: an anonymised file claims nothing.
    $case->forceFill(['anonymised_at' => now()])->save();

    expect(maskedOf($case->fresh())['duplicate_checked'])->toBeFalse();
});

it('closes on the association’s switch', function () {
    $case = publishedCase($this->region);

    Setting::put('show_duplicate_check', false);

    expect(maskedOf($case)['duplicate_checked'])->toBeFalse();

    $this->get(route('opportunity', $case->file_number))
        ->assertOk()
        ->assertDontSee(__('sanabel.masked.duplicate_checked'));
});
