<?php

use App\Http\Resources\MaskedCaseResource;
use App\Models\Asset;
use App\Models\Beneficiary;
use App\Models\Caregiver;
use App\Models\Debt;
use App\Models\HealthRecord;
use App\Models\HouseholdMember;
use App\Models\Livestock;
use App\Models\SupportSource;
use App\Services\NeedEngine;
use Illuminate\Support\Facades\DB;

/*
 | The association's own social survey, carried into the file: 104 columns on
 | their responses sheet and 19 on their dependants sheet. These assert the
 | two rules that shaped it — every number belonging to anybody is encrypted,
 | and a money row the association has not answered the question on is the
 | record of what was said, never an input to the engine.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->family = familyOf($this->region, adults: 1, children: 1);
});

it('holds what the survey asks about the household itself', function () {
    $this->family->update([
        'gender' => 'male',
        'birth_date' => '1984-03-02',
        'town_ar' => 'حي المنشية',
        'latitude' => 32.6189,
        'longitude' => 36.1021,
        'works' => true,
        'workplace_ar' => 'ورشة حدادة',
        'has_quran_memorizers' => true,
        'quran_memorizer_count' => 2,
        'livelihood_proposal_ar' => 'بسطة خضار',
        'proposal_skills_ar' => 'خبرة عشر سنوات بالبيع',
        'proposal_cost' => 5_000,
        'surveyed_by_ar' => 'أبو أحمد',
        'furnishing_ar' => 'فرش بسيط، ينقص غطاء شتوي',
    ]);

    $family = $this->family->fresh();

    expect($family->birth_date->format('Y-m-d'))->toBe('1984-03-02')
        ->and($family->works)->toBeTrue()
        ->and($family->quran_memorizer_count)->toBe(2)
        ->and($family->proposal_cost)->toBe(5_000);
});

it('encrypts every identity and telephone number, whosever it is', function () {
    $member = HouseholdMember::where('beneficiary_id', $this->family->id)->first();
    $member->update(['national_id_encrypted' => '11111111111']);

    $debt = Debt::create([
        'beneficiary_id' => $this->family->id,
        'creditor_name_ar' => 'أبو خالد',
        'amount' => 400_000,
        'creditor_phone_encrypted' => '0955111222',
    ]);

    $carer = Caregiver::create([
        'beneficiary_id' => $this->family->id,
        'name_ar' => 'أم محمد',
        'relation' => 'mother',
        'national_id_encrypted' => '22222222222',
    ]);

    $health = HealthRecord::create([
        'beneficiary_id' => $this->family->id,
        'severity_band' => 50,
        'description_ar' => 'سكري',
        'doctor_name_ar' => 'د. سامر',
        'doctor_phone_encrypted' => '0955333444',
    ]);

    // Readable through the model, unreadable in the table.
    expect($member->fresh()->national_id_encrypted)->toBe('11111111111')
        ->and($debt->fresh()->creditor_phone_encrypted)->toBe('0955111222')
        ->and($carer->fresh()->national_id_encrypted)->toBe('22222222222')
        ->and($health->fresh()->doctor_phone_encrypted)->toBe('0955333444');

    $raw = [
        DB::table('household_members')->where('id', $member->id)->value('national_id_encrypted'),
        DB::table('debts')->where('id', $debt->id)->value('creditor_phone_encrypted'),
        DB::table('caregivers')->where('id', $carer->id)->value('national_id_encrypted'),
        DB::table('health_records')->where('id', $health->id)->value('doctor_phone_encrypted'),
    ];

    foreach ($raw as $stored) {
        expect($stored)->not->toContain('1111111')
            ->and($stored)->not->toContain('0955');
    }
});

it('reads none of the new money rows until somebody answers the question on them', function () {
    // The computed snapshot stamps itself with the second it was computed in,
    // and this test compares two computations. It failed once on a full run
    // for no better reason than that they fell either side of a tick — so the
    // clock is held still, and the only difference left is the one under test.
    $this->freezeSecond();

    $before = app(NeedEngine::class)->compute($this->family->fresh());

    Asset::create([
        'beneficiary_id' => $this->family->id,
        'kind' => 'tractor',
        'details_ar' => 'جرار قديم',
        'estimated_value' => 9_000_000,
    ]);

    Livestock::create([
        'beneficiary_id' => $this->family->id,
        'kind' => 'sheep',
        'head_count' => 12,
        'income_kind' => 'partial_income',
        'monthly_income' => 300_000,
    ]);

    SupportSource::create([
        'beneficiary_id' => $this->family->id,
        'name_ar' => 'جمعية أخرى',
        'amount' => 150_000,
    ]);

    Debt::create([
        'beneficiary_id' => $this->family->id,
        'creditor_name_ar' => 'أبو خالد',
        'amount' => 400_000,
    ]);

    // The association answered these on 11 October, and each answer they gave
    // turns on a field nobody has filled in on the rows above:
    //
    //   - livestock needs `is_stable` (T-52, LivestockIncomeTest)
    //   - support from elsewhere needs a `coverage_month` (T-51,
    //     SupportFromElsewhereTest)
    //   - an asset needs its `necessity` (T-53, AssetNecessityTest), and even
    //     then only a threshold the association has yet to set would read it
    //
    // A debt is the one that stays out for good: it never entered the monthly
    // need, so it is neither added to it nor taken off it, and a qualifying
    // debt goes down its own path later.
    //
    // So an unanswered row moves nothing, which is what this holds: the
    // delegate recording what was said never silently changes a family's
    // figures.
    expect(app(NeedEngine::class)->compute($this->family->fresh()))->toBe($before);
});

it('keeps all of it away from the donor', function () {
    $case = publishedCase($this->region);

    Debt::create([
        'beneficiary_id' => $case->id,
        'creditor_name_ar' => 'أبو خالد',
        'amount' => 400_000,
        'creditor_phone_encrypted' => '0955111222',
    ]);

    $case->update([
        'town_ar' => 'حي المنشية',
        'latitude' => 32.6189,
        'longitude' => 36.1021,
        'surveyed_by_ar' => 'أبو أحمد',
    ]);

    $payload = (new MaskedCaseResource($case->fresh()))->toArray(request());
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    expect(array_diff(array_keys($payload), MaskedCaseResource::ALLOWED_KEYS))->toBeEmpty()
        ->and($json)->not->toContain('المنشية')
        ->and($json)->not->toContain('32.61')
        ->and($json)->not->toContain('أبو خالد')
        ->and($json)->not->toContain('أبو أحمد');
});

it('holds the dependant columns the second sheet carries', function () {
    $member = HouseholdMember::where('beneficiary_id', $this->family->id)->first();

    $member->update([
        'relation_other_ar' => 'ابن أخ',
        'birth_date' => '2012-09-01',
        'marital_status' => 'single',
        'occupation' => 'student',
        'school_grade_ar' => 'الصف السابع',
        'works' => false,
        'is_quran_memorizer' => true,
        'certificate_ar' => 'لا يوجد',
        'skills_ar' => 'يجيد الحاسوب',
    ]);

    $member = $member->fresh();

    expect($member->occupation)->toBe('student')
        ->and($member->birth_date->format('Y-m-d'))->toBe('2012-09-01')
        ->and($member->is_quran_memorizer)->toBeTrue()
        // The year the engine reads is untouched by the exact date.
        ->and($member->birth_year)->not->toBeNull();
});

it('holds the housing and treatment columns', function () {
    $this->family->housing->update([
        'type_other_ar' => 'سكن مع الأهل',
        'rent_period' => 'monthly',
        'bathrooms' => 1,
        'has_kitchen' => false,
    ]);

    $record = HealthRecord::create([
        'beneficiary_id' => $this->family->id,
        'severity_band' => 50,
        'ongoing_treatment' => true,
        'treatment_place_ar' => 'مشفى درعا الوطني',
        'medicines_ar' => 'أنسولين',
    ]);

    expect($this->family->housing->fresh()->has_kitchen)->toBeFalse()
        ->and($this->family->housing->fresh()->bathrooms)->toBe(1)
        ->and($record->fresh()->ongoing_treatment)->toBeTrue()
        ->and($record->fresh()->treatment_place_ar)->toBe('مشفى درعا الوطني');
});
