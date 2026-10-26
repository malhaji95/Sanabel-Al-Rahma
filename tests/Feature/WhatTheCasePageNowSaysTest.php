<?php

use App\Models\HealthRecord;
use App\Models\Setting;

/*
 | T-158 — «نحتاج هذي المعلومات تكون ظاهرة بصفحة الحالة: قيمة الدين, هل يوجد
 | معيل, المرض شدة الحالة, تكلفة الطبية للعلاج, وضع فرش المنزل, نوع الأدوية,
 | خطر الاخلاء», 29 October.
 |
 | Five of the seven are a band the researcher already grades or a figure
 | already on the file, and they publish under the rule the rest of the page
 | publishes under. «وضع فرش المنزل» (`furnishing_ar`) and «نوع الأدوية»
 | (`medicines_ar`) are free text written by the researcher for the committee,
 | and rule 2 keeps every sentence off a donor's screen — so they are not
 | here, and the tests below hold that line.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('names the worst illness band and what the treatment costs in a month', function () {
    $case = publishedCase($this->region);

    HealthRecord::factory()->create([
        'beneficiary_id' => $case->id,
        'severity_band' => 50,
        'monthly_medical_cost' => 30_000,
    ]);

    HealthRecord::factory()->create([
        'beneficiary_id' => $case->id,
        'severity_band' => 75,
        'monthly_medical_cost' => 20_000,
    ]);

    $masked = maskedOf($case);

    expect($masked['illness_severity_label'])->toBe(__('sanabel.bands.severity.75'))
        ->and($masked['monthly_medical_cost'])->toBe(50_000);
});

it('says whether the house is under threat of eviction', function () {
    $case = publishedCase($this->region);

    $case->housing()->updateOrCreate(
        ['beneficiary_id' => $case->id],
        ['housing_type' => 'rented', 'eviction_band' => 75],
    );

    expect(maskedOf($case)['eviction_label'])->toBe(__('sanabel.bands.eviction.75'));
});

it('answers whether anybody in the household earns', function () {
    $case = publishedCase($this->region);

    $case->forceFill(['work_status' => 'seeking'])->save();
    expect(maskedOf($case)['has_breadwinner'])->toBeFalse();

    // And it closes with the block it describes.
    Setting::put('show_household_profile', false);
    expect(maskedOf($case)['has_breadwinner'])->toBeNull();
    Setting::put('show_household_profile', true);

    $case->forceFill(['work_status' => 'yes'])->save();
    expect(maskedOf($case)['has_breadwinner'])->toBeTrue();

    // A jobseeker is not a breadwinner, and neither is a student.
    $case->forceFill(['work_status' => 'no'])->save();
    $case->members()->first()?->forceFill(['occupation' => 'jobseeker'])->save();
    expect(maskedOf($case)['has_breadwinner'])->toBeFalse();

    $case->members()->first()?->forceFill(['occupation' => 'worker'])->save();
    expect(maskedOf($case)['has_breadwinner'])->toBeTrue();
});

it('still keeps every sentence the researcher wrote off the page', function () {
    $case = publishedCase($this->region);

    $case->forceFill(['furnishing_ar' => 'فرش قديم وبرّاد معطّل'])->save();

    HealthRecord::factory()->create([
        'beneficiary_id' => $case->id,
        'medicines_ar' => 'إنسولين ومضاد حيوي',
        'severity_band' => 50,
    ]);

    $masked = maskedOf($case);
    $flat = json_encode($masked, JSON_UNESCAPED_UNICODE);

    expect($flat)->not->toContain('فرش قديم')
        ->not->toContain('إنسولين')
        ->and($masked)->not->toHaveKey('furnishing_ar')
        ->and($masked)->not->toHaveKey('medicines_ar');
});

it('closes the new rows with the switch that closes the block they belong to', function () {
    $case = publishedCase($this->region);

    HealthRecord::factory()->create([
        'beneficiary_id' => $case->id,
        'severity_band' => 50,
        'monthly_medical_cost' => 30_000,
    ]);

    $case->housing()->updateOrCreate(['beneficiary_id' => $case->id], ['eviction_band' => 75]);

    Setting::put('show_health_condition', false);
    Setting::put('show_housing_detail', false);

    $masked = maskedOf($case);

    expect($masked['illness_severity_label'])->toBeNull()
        ->and($masked['monthly_medical_cost'])->toBeNull()
        ->and($masked['eviction_label'])->toBeNull();
});
