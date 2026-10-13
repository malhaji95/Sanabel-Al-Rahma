<?php

use App\Models\Beneficiary;
use App\Models\ChangeRequest;
use App\Services\CaseService;

/*
 | T-73 — the association's answer of 13 October.
 |
 | Their filled dossier carries «التوصية: مستحق للزكاة» written by the
 | researcher, and we asked whether that feeds the zakat field or stays an
 | opinion. Their answer was that the field is the management's authority.
 |
 | So the two questions are two columns: the researcher recommends, the
 | management decides, and no account that cannot approve a file can set the
 | channel the money is paid from — through any door.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->director = userWithRole('executive_director');
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $this->delegate = userWithRole('delegate', ['region_id' => $this->region->id]);
});

it('lets the management set the zakat channel', function () {
    $case = familyOf($this->region);

    $this->actingAs($this->director);
    $case->update(['zakat_category' => 'poor']);

    expect($case->fresh()->zakat_category)->toBe('poor');
})->group('AUTH-03');

it('refuses the channel to an account that cannot approve a file', function () {
    $case = familyOf($this->region);

    foreach ([$this->officer, $this->delegate] as $user) {
        $this->actingAs($user);

        expect(fn () => $case->update(['zakat_category' => 'needy']))
            ->toThrow(RuntimeException::class);

        expect($case->fresh()->zakat_category)->not->toBe('needy');
    }
})->group('AUTH-03');

it('refuses it on a new file too, not only on an edit', function () {
    $this->actingAs($this->delegate);

    expect(fn () => Beneficiary::factory()->create([
        'region_id' => $this->region->id,
        'zakat_category' => 'poor',
    ]))->toThrow(RuntimeException::class);
})->group('AUTH-03');

it('leaves the rest of the file editable by the people who fill it', function () {
    $case = familyOf($this->region);

    $this->actingAs($this->officer);
    $case->update(['previous_aid_ar' => 'سلة غذائية في آب']);

    expect($case->fresh()->previous_aid_ar)->toBe('سلة غذائية في آب');
});

it('records the researcher recommendation, and lets a researcher enter it', function () {
    $case = familyOf($this->region);

    $this->actingAs($this->delegate);
    $case->update(['zakat_recommended' => true]);

    expect($case->fresh()->zakat_recommended)->toBeTrue();
});

it('never lets the recommendation decide the channel by itself', function () {
    $case = familyOf($this->region);

    $this->actingAs($this->delegate);
    $case->update(['zakat_recommended' => true]);

    // The recommendation is in, and the channel is still empty: entitlement is
    // not set by the person who visited the family.
    expect($case->fresh()->zakat_recommended)->toBeTrue()
        ->and($case->fresh()->zakat_category)->toBeNull();
});

it('keeps the channel off the change-request allowlist, so it cannot come in the back way', function () {
    expect(in_array('zakat_category', ChangeRequest::CHANGEABLE_FIELDS, true))->toBeFalse()
        // The recommendation is data, so a researcher may correct it.
        ->and(in_array('zakat_recommended', ChangeRequest::CHANGEABLE_FIELDS, true))->toBeTrue();
})->group('AUTH-03');

it('refuses a change request that carries the channel', function () {
    $case = publishedCase($this->region);

    $this->actingAs($this->officer);

    expect(fn () => app(CaseService::class)->requestChange(
        $case,
        $this->officer,
        ['zakat_category' => 'debtors'],
        'تصنيف الزكاة',
    ))->toThrow(RuntimeException::class);

    expect($case->fresh()->zakat_category)->toBeNull();
})->group('AUTH-03');

it('leaves a seeder and an import alone, because there is nobody to check', function () {
    // No signed-in user: a console command, a seeder, the old-system import.
    // The rule is about who decides, and in those paths nobody is deciding.
    $case = familyOf($this->region);
    $case->update(['zakat_category' => 'wayfarer']);

    expect($case->fresh()->zakat_category)->toBe('wayfarer');
});

it('says on the screen who may set it, before anyone types', function () {
    $source = file_get_contents(app_path('Filament/Resources/BeneficiaryResource.php'));

    expect(str_contains($source, "->disabled(fn () => ! auth()->user()?->can_('approve_case'))"))->toBeTrue(
        'the channel is not locked on the form for an account that cannot approve'
    )->and(str_contains($source, 'dehydrated'))->toBeTrue(
        'a locked field that still submits would be refused by the model with a confusing error'
    );
})->group('AUTH-03');
