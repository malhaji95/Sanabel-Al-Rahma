<?php

use App\Models\Beneficiary;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;

/*
 | T-74 — the association's decisions of 13 October.
 |
 | «المناديب يقدر يطلع على بيانات قريته فقط، المشرفين على بيانات منطقته» — the
 | scope was always the account's region and everything under it, so what this
 | adds is the level: nothing used to stop a delegate being attached to an
 | area, and an area has villages under it.
 |
 | «موافق ولا يوجد لا، في حال غير موافق ما يدخل للنظام، ونعتمد انها ما تفتح
 | الا بعد الاطلاع عليها» — two refusals where there used to be a notice.
 */

beforeEach(function () {
    seedCore();
    $this->governorate = regionWithRates();
    $this->area = Region::factory()->create(['parent_id' => $this->governorate->id]);
    $this->village = Region::factory()->village()->create(['parent_id' => $this->area->id]);
});

it('attaches a delegate to a village and refuses anything wider', function () {
    $delegate = userWithRole('delegate', ['region_id' => $this->village->id]);

    expect($delegate->fresh()->region_id)->toBe($this->village->id);

    foreach (['area' => $this->area, 'governorate' => $this->governorate] as $level => $region) {
        expect(fn () => userWithRole('delegate', ['region_id' => $region->id]))
            ->toThrow(RuntimeException::class, '', "a delegate was attached to a {$level}");
    }
})->group('AUTH-02');

it('attaches a supervisor to an area and refuses a village or a governorate', function () {
    $supervisor = userWithRole('area_supervisor', ['region_id' => $this->area->id]);

    expect($supervisor->fresh()->region_id)->toBe($this->area->id);

    expect(fn () => userWithRole('area_supervisor', ['region_id' => $this->village->id]))
        ->toThrow(RuntimeException::class)
        ->and(fn () => userWithRole('area_supervisor', ['region_id' => $this->governorate->id]))
        ->toThrow(RuntimeException::class);
})->group('AUTH-02');

it('refuses a move to the wrong level on an existing account, not only at its creation', function () {
    $delegate = userWithRole('delegate', ['region_id' => $this->village->id]);

    expect(fn () => $delegate->update(['region_id' => $this->area->id]))
        ->toThrow(RuntimeException::class);

    expect($delegate->fresh()->region_id)->toBe($this->village->id);
})->group('AUTH-02');

it('refuses the level when a role changes under an account, too', function () {
    $officer = userWithRole('case_officer', ['region_id' => $this->area->id]);

    // The same region, now with a role that may not hold it.
    expect(fn () => $officer->update([
        'role_id' => Role::where('key', 'delegate')->value('id'),
    ]))->toThrow(RuntimeException::class);
})->group('AUTH-02');

it('leaves the roles with no level of their own alone', function () {
    foreach (['case_officer', 'finance', 'content_manager', 'executive_director'] as $role) {
        $user = userWithRole($role, ['region_id' => $this->governorate->id]);

        expect($user->fresh()->region_id)->toBe($this->governorate->id);
    }
});

it('says which level the role needs, so the fix is one click', function () {
    try {
        userWithRole('delegate', ['region_id' => $this->area->id]);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain(__('sanabel.region_type.village'))
            ->and($e->getMessage())->toContain(__('sanabel.region_type.area'));

        return;
    }

    $this->fail('the wrong level was accepted');
});

it('opens the chairman the deputy they named, with the same keys and a second factor', function () {
    $deputy = userWithRole('deputy_board_director');

    expect($deputy->role->name_ar)->toBe('نائب مدير مجلس الأمناء')
        ->and($deputy->can_('view_full_case'))->toBeTrue()
        ->and($deputy->can_('approve_case'))->toBeTrue()
        ->and($deputy->requiresTwoFactor())->toBeTrue();
})->group('IAM-01');

/* الموافقات */

it('refuses a consent recorded for a policy nobody was shown', function () {
    $this->actingAs(userWithRole('case_officer'));

    expect(fn () => familyOf($this->area, attributes: [
        'privacy_policy_seen' => false,
        'privacy_consent' => true,
    ]))->toThrow(RuntimeException::class);
});

it('accepts the consent once the policy has been shown', function () {
    $this->actingAs(userWithRole('case_officer'));

    $case = familyOf($this->area, attributes: [
        'privacy_policy_seen' => true,
        'privacy_consent' => true,
        'privacy_consent_on' => now()->toDateString(),
    ]);

    expect($case->fresh()->privacy_consent)->toBeTrue()
        ->and($case->privacyConsentIsSound())->toBeTrue();
});

it('keeps a file in draft while the consent is missing', function () {
    $this->actingAs(userWithRole('case_officer'));

    $case = familyOf($this->area);

    expect(fn () => $case->update(['status' => 'pending_visit']))
        ->toThrow(RuntimeException::class);

    expect($case->fresh()->status)->toBe('draft');
});

it('lets the file move on once the family has seen the policy and agreed', function () {
    $this->actingAs(userWithRole('case_officer'));

    $case = familyOf($this->area, attributes: [
        'privacy_policy_seen' => true,
        'privacy_consent' => true,
    ]);

    $case->update(['status' => 'pending_visit']);

    expect($case->fresh()->status)->toBe('pending_visit');
});

it('lets a draft be filled in any order before the consent step is reached', function () {
    $this->actingAs(userWithRole('case_officer'));

    $case = familyOf($this->area);
    $case->update(['previous_aid_ar' => 'سلة غذائية']);

    expect($case->fresh()->previous_aid_ar)->toBe('سلة غذائية')
        ->and($case->fresh()->status)->toBe('draft');
});

it('still carries an old row exactly as the old form was filled', function () {
    // Their own filled dossier says «الاطلاع: لا» beside «الموافقة: نعم». The
    // import runs with nobody signed in, so the row comes across as it stands
    // and keeps the review notice: refusing it would lose the family rather
    // than fix a form that is already printed.
    $case = Beneficiary::factory()->create([
        'region_id' => $this->area->id,
        'privacy_policy_seen' => false,
        'privacy_consent' => true,
    ]);

    expect($case->fresh()->privacyConsentContradicts())->toBeTrue()
        ->and($case->fresh()->privacyConsentIsSound())->toBeFalse();
});

it('closes the consent toggle on the screen until the policy has been shown', function () {
    $source = file_get_contents(app_path('Filament/Resources/BeneficiaryResource.php'));

    expect(str_contains($source, "->disabled(fn (Forms\\Get \$get) => \$get('privacy_policy_seen') !== true)"))->toBeTrue(
        'the consent can still be ticked before the policy was shown'
    );
});

it('offers the user form only the regions the role may hold', function () {
    $source = file_get_contents(app_path('Filament/Resources/UserResource.php'));

    expect(str_contains($source, 'User::ROLE_REGION_LEVEL'))->toBeTrue(
        'the region list is not narrowed to the level the role needs'
    );

    expect(User::ROLE_REGION_LEVEL)->toHaveKey('delegate')
        ->and(User::ROLE_REGION_LEVEL['delegate'])->toBe('village')
        ->and(User::ROLE_REGION_LEVEL['area_supervisor'])->toBe('area');
});
