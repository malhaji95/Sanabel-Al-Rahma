<?php

use App\Http\Resources\CoordinationLookupResource;
use App\Models\Beneficiary;
use App\Models\Region;
use App\Services\DuplicateService;

/*
 | T-182 — «بالنسبة للمعلومات يتم الاطلاع على الحالات التي تم إضافتها من قبله,
 | ولكن الاستعلام برقم الهوية يتم ضمن منطقته كاملة, ويظهر فقط مسجلة مقيمة وهل
 | مدعومة, بدون اسم أو عنوان», 29 October.
 |
 | The association's list stays its own files, which is what it already was.
 | What the lookup was missing is the middle word: it answered «مسجلة» across
 | the whole country — which is what stops two associations opening one family
 | twice — and never answered the question the association is actually asking,
 | «is she one of mine». That is «مقيمة», and it is a yes or a no: the place
 | itself never appears, here or anywhere on this screen.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->lookup = app(DuplicateService::class);
});

it('says yes to a family in a village under the asking account’s own area', function () {
    $village = placeIn($this->region, 'village');
    $case = publishedCase($this->region);
    $case->forceFill(['region_id' => $village->id, 'national_id_encrypted' => '09111222333'])->save();
    $case->forceFill(['national_id_hash' => Beneficiary::hashNationalId('09111222333')])->save();

    // The association sits on the area above that village, and coordinates
    // across all of it rather than across the files it happens to have opened.
    $association = userWithRole('association', ['region_id' => $village->ancestorOfType('area')->id]);

    expect($this->lookup->coordinationLookup('09111222333', $association))
        ->toMatchArray(['registered' => true, 'resident' => true]);
});

it('says no for a family registered somewhere else entirely', function () {
    $case = publishedCase($this->region);
    $case->forceFill([
        'national_id_encrypted' => '09444555666',
        'national_id_hash' => Beneficiary::hashNationalId('09444555666'),
    ])->save();

    // Another area, with nothing above it in common but the governorate.
    $elsewhere = Region::withoutGlobalScopes()->create([
        'name_ar' => 'منطقة أخرى', 'type' => 'area', 'parent_id' => null, 'is_active' => true,
    ]);
    $association = userWithRole('association', ['region_id' => $elsewhere->id]);

    $result = $this->lookup->coordinationLookup('09444555666', $association);

    // Still registered — that answer is national, and it is the one that stops
    // the same family being opened twice by two associations.
    expect($result['registered'])->toBeTrue()
        ->and($result['resident'])->toBeFalse();
});

it('says no, rather than guessing, for an account tied to no area at all', function () {
    $case = publishedCase($this->region);
    $case->forceFill([
        'national_id_encrypted' => '09777888999',
        'national_id_hash' => Beneficiary::hashNationalId('09777888999'),
    ])->save();

    expect($this->lookup->coordinationLookup('09777888999', userWithRole('admin')))
        ->toMatchArray(['registered' => true, 'resident' => false]);
});

it('answers a family nobody has registered without inventing one', function () {
    expect($this->lookup->coordinationLookup('09000000000', userWithRole('association')))
        ->toMatchArray([
            'registered' => false,
            'resident' => false,
            'assessment_state' => 'none',
            'supported_this_period' => false,
            'coverage' => 'none',
        ]);
});

it('still returns a handful of flags and nothing that reaches the family', function () {
    $case = publishedCase($this->region);
    $case->forceFill([
        'first_name' => 'سعاد',
        'family_name' => 'الحلبي',
        'national_id_encrypted' => '09123456789',
        'national_id_hash' => Beneficiary::hashNationalId('09123456789'),
    ])->save();

    $payload = (new CoordinationLookupResource(
        $this->lookup->coordinationLookup('09123456789', userWithRole('association'))
    ))->resolve();

    expect(array_keys($payload))->toEqualCanonicalizing(CoordinationLookupResource::ALLOWED_KEYS)
        ->and(json_encode($payload, JSON_UNESCAPED_UNICODE))
        ->not->toContain('سعاد')
        ->not->toContain('الحلبي')
        ->not->toContain($case->file_number)
        // «مقيمة» is a yes or a no; the place behind it never travels with it.
        ->not->toContain((string) $case->region_id);
})->group('AUTH-04');
