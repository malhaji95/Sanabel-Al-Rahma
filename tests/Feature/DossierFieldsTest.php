<?php

use App\Models\Media;
use App\Services\AnonymiseService;
use App\Services\NeedEngine;

/*
 | T-69 — what the filled dossier showed that the column list did not.
 |
 | The association sent one completed survey (SR0000035) on 12 October. Read
 | against the 104 header names, it showed ten things the names alone could
 | not, because they are about how a field is used rather than what it is
 | called.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->family = familyOf($this->region, adults: 1, children: 1);
});

it('records the four consents the dossier asks for', function () {
    $this->family->update([
        'donor_visit_consent' => true,
        'donor_visit_consent_on' => '2026-10-12',
        'privacy_policy_seen' => true,
        'privacy_consent' => true,
        'privacy_consent_on' => '2026-10-12',
        'privacy_policy_version' => 'v1.0',
        'declaration_on' => '2026-10-12',
    ]);

    $case = $this->family->fresh();

    expect($case->donor_visit_consent)->toBeTrue()
        ->and($case->donor_visit_consent_on->toDateString())->toBe('2026-10-12')
        ->and($case->privacy_policy_version)->toBe('v1.0')
        ->and($case->declaration_on->toDateString())->toBe('2026-10-12');
});

it('sends no donor to a family that has not agreed to a visit', function () {
    // The consent is the thing that makes a visit permissible, so no answer
    // is not a yes.
    expect($this->family->acceptsDonorVisit())->toBeFalse();

    $this->family->update(['donor_visit_consent' => false]);
    expect($this->family->fresh()->acceptsDonorVisit())->toBeFalse();

    $this->family->update(['donor_visit_consent' => true]);
    expect($this->family->fresh()->acceptsDonorVisit())->toBeTrue();
});

it('can record and report the contradiction their own form produces', function () {
    /*
     | Their sample answers «الاطلاع على سياسة الخصوصية: لا» and «الموافقة على
     | سياسة الخصوصية: نعم» — a consent to a text nobody read. One column
     | could not express that, and it has to be expressible before it can be
     | reported. The row is kept as it was given; refusing it would lose the
     | family.
     */
    $this->family->update(['privacy_policy_seen' => false, 'privacy_consent' => true]);

    $case = $this->family->fresh();

    expect($case->privacyConsentContradicts())->toBeTrue()
        ->and($case->privacyConsentIsSound())->toBeFalse();

    $case->update(['privacy_policy_seen' => true]);

    expect($case->fresh()->privacyConsentContradicts())->toBeFalse()
        ->and($case->fresh()->privacyConsentIsSound())->toBeTrue();
});

it('holds the quarter apart from the town, because the dossier asks both', function () {
    $this->family->update(['town_ar' => 'بلدة مليحة العطش', 'quarter_ar' => 'الجنوبي']);

    expect($this->family->fresh()->town_ar)->toBe('بلدة مليحة العطش')
        ->and($this->family->fresh()->quarter_ar)->toBe('الجنوبي');
});

it('records the income the family stated without the engine reading it', function () {
    $before = app(NeedEngine::class)->compute($this->family);

    $this->family->update(['declared_monthly_income' => 500]);

    $after = app(NeedEngine::class)->compute($this->family->fresh());

    expect($this->family->fresh()->declared_monthly_income)->toBe(500)
        // The engine reads the income rows, as it always has.
        ->and($after['stable_income'])->toBe($before['stable_income'])
        ->and($after['monthly_need'])->toBe($before['monthly_need']);
});

it('marks a dependant as an orphan without touching the score', function () {
    $member = $this->family->members()->first();
    $before = app(NeedEngine::class)->compute($this->family);

    $member->update(['is_orphan' => true]);

    expect($member->fresh()->is_orphan)->toBeTrue()
        // The V factor reads a member's relation against the orphan keys in
        // config, exactly as before. This is recorded, not computed from.
        ->and(app(NeedEngine::class)->compute($this->family->fresh())['f'])->toBe($before['f']);
});

it('encrypts a married dependant\'s husband identity number too', function () {
    $member = $this->family->members()->first();
    $member->update(['spouse_name_ar' => 'محمد', 'spouse_national_id_encrypted' => '12341234123']);

    expect($member->fresh()->spouse_national_id_encrypted)->toBe('12341234123');

    $stored = \Illuminate\Support\Facades\DB::table('household_members')
        ->where('id', $member->getKey())->value('spouse_national_id_encrypted');

    expect($stored)->not->toContain('12341234123');

    // And it is hidden from any array the model is turned into.
    expect($member->fresh()->toArray())->not->toHaveKey('spouse_national_id_encrypted');
});

it('keeps the nature of a debt apart from its reason', function () {
    $debt = $this->family->debts()->create([
        'creditor_name_ar' => 'محمد',
        'amount' => 10_000,
        'nature_ar' => 'اغراض',
        'reason_ar' => 'تجارة',
    ]);

    expect($debt->fresh()->nature_ar)->toBe('اغراض')
        ->and($debt->fresh()->reason_ar)->toBe('تجارة');
});

it('records whose property an asset is', function () {
    // The dossier asks after the spouse's own property in its own right, and a
    // committee weighing what a family controls needs to know whose it is.
    $asset = $this->family->assets()->create([
        'kind' => 'land',
        'belongs_to' => 'spouse',
        'necessity' => 'surplus',
        'details_ar' => 'ارض ومنزل ومحل موجر',
        'estimated_value' => 9_000_000,
    ]);

    expect($asset->fresh()->belongs_to)->toBe('spouse')
        ->and(__('sanabel.asset.belongs_to_options'))->toHaveKeys(['head', 'spouse']);
});

it('records what relatives and neighbours give apart from what the carer gives', function () {
    // The dossier carries both: 200 from the carer, 50 from relatives and
    // neighbours. One column could only hold one of them.
    $carer = $this->family->caregivers()->create([
        'name_ar' => 'أم محمد',
        'relation' => 'mother',
        'monthly_support' => 200,
        'neighbours_help' => true,
        'neighbours_support' => 50,
        'neighbours_relation' => 'other',
    ]);

    expect($carer->fresh()->monthly_support)->toBe(200)
        ->and($carer->fresh()->neighbours_support)->toBe(50)
        ->and($carer->fresh()->neighbours_relation)->toBe('other');
});

it('has a name for the papers the dossier lists', function () {
    // «شهادة وفاة الزوج» appears in the supporting documents and there was no
    // kind here to carry it, so it would have landed under "other".
    expect(Media::FAMILY_KINDS)->toContain('death_certificate')
        ->and(__('sanabel.document.kinds.death_certificate'))->not->toBe('sanabel.document.kinds.death_certificate');
});

it('destroys every one of the new personal columns on anonymisation', function () {
    foreach ([
        'quarter_ar' => \App\Models\Beneficiary::class,
        'spouse_national_id_encrypted' => \App\Models\HouseholdMember::class,
        'nature_ar' => \App\Models\Debt::class,
        'neighbours_relation' => \App\Models\Caregiver::class,
    ] as $column => $class) {
        $named = array_merge(
            AnonymiseService::CLEARED[$class] ?? [],
            AnonymiseService::MARKED[$class] ?? [],
        );

        expect(in_array($column, $named, true))->toBeTrue(
            $column.' is personal and the sweep does not name it',
        );
    }
});
