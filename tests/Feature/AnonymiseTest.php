<?php

use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\Media;
use App\Services\AnonymiseService;
use App\Services\CoverageService;
use App\Services\DuplicateService;
use App\Services\MediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 | T-65 — the association's resolution of 12 October.
 |
 | The roadmap asks for legal deletion of personal data; rule 3 forbids erasing
 | a beneficiary, a case or a donation. Their answer was to separate the two:
 | the record is never deleted, and the personal data identifying a human being
 | is destroyed when the law requires it and the retention period has run out.
 |
 | Their own example: file SR-10542 keeps its aid history, its approvals, its
 | financial reports and its audit trail. The name, the phone, the address and
 | the identity number become nothing.
 */

beforeEach(function () {
    seedCore();
    Storage::fake('media_test');
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $this->admin = userWithRole('admin', ['region_id' => $this->region->id]);
    $this->service = app(AnonymiseService::class);
});

function familyWithEverything($region): Beneficiary
{
    $case = publishedCase($region, attributes: [
        'phone_encrypted' => '0911111111',
        'wallet_encrypted' => '0922222222',
        'town_ar' => 'حي المنشية',
        'legacy_file_no' => 'OLD-1042',
        'surveyed_by_ar' => 'أبو محمد',
        'latitude' => 32.6189,
    ]);

    $case->housing()->first()->update([
        'landlord_name_ar' => 'أبو فؤاد',
        'landlord_phone_encrypted' => '0933444555',
    ]);

    $case->healthRecords()->create([
        'severity_band' => 25, 'economic_impact_band' => 0, 'care_burden_band' => 0,
        'monthly_medical_cost' => 0, 'description_ar' => 'سكري',
        'doctor_name_ar' => 'د. سامر', 'doctor_phone_encrypted' => '0944555666',
    ]);

    $case->debts()->create([
        'creditor_name_ar' => 'أبو خالد', 'amount' => 400_000,
        'creditor_phone_encrypted' => '0955111222', 'creditor_address_ar' => 'حي المنشية',
    ]);

    $case->caregivers()->create([
        'name_ar' => 'أم محمد', 'relation' => 'mother',
        'national_id_encrypted' => '01234567890', 'monthly_support' => 50_000,
    ]);

    $case->assets()->create([
        'kind' => 'land', 'necessity' => 'surplus',
        'estimated_value' => 9_000_000, 'details_ar' => 'أرض بجانب بيت أبو خالد',
    ]);

    return $case->refresh();
}

it('destroys the person and keeps the record', function () {
    $case = familyWithEverything($this->region);
    $fileNumber = $case->file_number;
    $need = app(CoverageService::class)->needAmount($case);
    $members = $case->members()->count();

    $this->service->request($case, $this->officer, 'انتهت مدة الاحتفاظ النظامية وصدر التزام قانوني بالإتلاف');
    $this->service->execute($case->fresh(), $this->admin);

    $case = Beneficiary::withoutGlobalScopes()->find($case->getKey());

    // Gone.
    // Both are NOT NULL on the table, so they take a marker and a fresh
    // random token rather than nothing — and neither matches anybody.
    expect($case->national_id_encrypted)->toBe('مطموس')
        ->and($case->national_id_hash)->toStartWith('anonymised:')
        ->and($case->phone_encrypted)->toBeNull()
        ->and($case->wallet_encrypted)->toBeNull()
        ->and($case->town_ar)->toBeNull()
        ->and($case->latitude)->toBeNull()
        ->and($case->surveyed_by_ar)->toBeNull()
        ->and($case->legacy_file_no)->toBeNull()
        ->and($case->first_name)->toBe('مطموس')
        ->and($case->family_name)->toBe('مطموس');

    // Still there, which is the half they asked for.
    expect($case->file_number)->toBe($fileNumber)
        ->and($case->region_id)->not->toBeNull()
        ->and($case->status)->toBe('published')
        ->and($case->members()->count())->toBe($members)
        ->and(app(CoverageService::class)->needAmount($case))->toBe($need)
        ->and($case->assessments()->count())->toBeGreaterThan(0)
        ->and($case->isAnonymised())->toBeTrue();
});

it('sweeps every record hanging off the file, not just the file', function () {
    $case = familyWithEverything($this->region);

    $this->service->request($case, $this->officer, 'سند قانوني');
    $this->service->execute($case->fresh(), $this->admin);

    $case->refresh();

    expect($case->housing()->first()->landlord_name_ar)->toBeNull()
        ->and($case->housing()->first()->landlord_phone_encrypted)->toBeNull()
        ->and($case->healthRecords()->first()->doctor_name_ar)->toBeNull()
        ->and($case->healthRecords()->first()->doctor_phone_encrypted)->toBeNull()
        ->and($case->debts()->first()->creditor_phone_encrypted)->toBeNull()
        ->and($case->debts()->first()->creditor_name_ar)->toBe('مطموس')
        ->and($case->caregivers()->first()->national_id_encrypted)->toBeNull()
        ->and($case->caregivers()->first()->name_ar)->toBe('مطموس')
        ->and($case->assets()->first()->details_ar)->toBeNull()
        // The figures each of those carries are untouched.
        ->and($case->debts()->first()->amount)->toBe(400_000)
        ->and($case->caregivers()->first()->monthly_support)->toBe(50_000)
        ->and($case->assets()->first()->estimated_value)->toBe(9_000_000);

    foreach ($case->members as $member) {
        expect($member->name_ar)->toBe('مطموس')
            ->and($member->national_id_encrypted)->toBeNull()
            // What the engine reads survives.
            ->and($member->person_class)->not->toBeNull()
            ->and($member->birth_year)->not->toBeNull();
    }
});

it('destroys the papers themselves, not only their rows', function () {
    $case = familyWithEverything($this->region);
    $media = app(MediaService::class)->store(
        UploadedFile::fake()->image('identity.jpg'),
        $case,
        'identity',
        $this->officer,
    );
    $key = $media->storage_key;

    expect(Storage::disk('media_test')->exists($key))->toBeTrue();

    $this->service->request($case, $this->officer, 'سند قانوني');
    $this->service->execute($case->fresh(), $this->admin);

    // An identity card photograph is the identity.
    expect(Storage::disk('media_test')->exists($key))->toBeFalse()
        // The row is archived, not erased (rule 3), and serves nothing.
        ->and(Media::withTrashed()->whereKey($media->getKey())->exists())->toBeTrue()
        ->and(Media::withTrashed()->find($media->getKey())->isServable())->toBeFalse();
});

it('closes their own portal account', function () {
    $case = familyWithEverything($this->region);
    $account = userWithRole('beneficiary', ['name' => 'محمد أحمد', 'email' => 'm@example.test']);
    $case->forceFill(['user_id' => $account->getKey()])->save();

    $this->service->request($case, $this->officer, 'سند قانوني');
    $this->service->execute($case->fresh(), $this->admin);

    $account->refresh();

    expect($account->name)->toBe('مطموس')
        ->and($account->email)->not->toContain('m@example.test')
        ->and($account->is_active)->toBeFalse();
});

it('keeps the audit trail and takes the values out of it', function () {
    $case = familyWithEverything($this->region);

    // An edit to the name was logged with the name in it.
    $case->update(['family_name' => 'الحلبي']);

    $rowsBefore = AuditLog::where('entity_type', Beneficiary::class)
        ->where('entity_id', $case->getKey())->count();

    expect($rowsBefore)->toBeGreaterThan(0);

    $this->service->request($case, $this->officer, 'سند قانوني');
    $this->service->execute($case->fresh(), $this->admin);

    $rows = DB::table('audit_log')
        ->where('entity_type', Beneficiary::class)
        ->where('entity_id', $case->getKey())
        ->get();

    // The rows stay: they asked for the audit trail explicitly.
    expect($rows->count())->toBeGreaterThanOrEqual($rowsBefore);

    // The name is nowhere in any of them.
    $everything = $rows->map(fn ($r) => (string) $r->before_json.(string) $r->after_json)->implode(' ');

    expect($everything)->not->toContain('الحلبي')
        ->not->toContain('0911111111')
        ->not->toContain('حي المنشية')
        ->not->toContain('OLD-1042');
});

it('writes one audit row for the act, naming what went and never a value', function () {
    $case = familyWithEverything($this->region);

    $this->service->request($case, $this->officer, 'انتهت مدة الاحتفاظ');
    $this->service->execute($case->fresh(), $this->admin);

    $row = AuditLog::where('action', 'anonymised')
        ->where('entity_id', $case->getKey())->first();

    expect($row)->not->toBeNull()
        ->and($row->actor_id)->toBe($this->admin->getKey())
        ->and($row->after_json['reason_ar'])->toBe('انتهت مدة الاحتفاظ')
        ->and($row->after_json['requested_by'])->toBe($this->officer->getKey())
        ->and($row->after_json['cleared'])->toHaveKeys(['beneficiary', 'media', 'audit_rows'])
        // Column names and counts, not values.
        ->and(json_encode($row->after_json, JSON_UNESCAPED_UNICODE))->not->toContain('حي المنشية');
});

it('needs two people, and refuses the one who asked', function () {
    $case = familyWithEverything($this->region);

    // Not asked for at all.
    expect(fn () => $this->service->execute($case, $this->admin))->toThrow(RuntimeException::class);

    $this->service->request($case, $this->officer, 'سند قانوني');

    // Asked for by the same person who would carry it out.
    expect(fn () => $this->service->execute($case->fresh(), $this->officer))
        ->toThrow(RuntimeException::class);

    expect($case->fresh()->isAnonymised())->toBeFalse()
        ->and($case->fresh()->phone_encrypted)->toBe('0911111111');
});

it('refuses a request with no legal basis behind it', function () {
    $case = familyWithEverything($this->region);

    expect(fn () => $this->service->request($case, $this->officer, '   '))
        ->toThrow(RuntimeException::class);
});

it('refuses to do it twice', function () {
    $case = familyWithEverything($this->region);

    $this->service->request($case, $this->officer, 'سند قانوني');
    $this->service->execute($case->fresh(), $this->admin);

    expect(fn () => $this->service->execute($case->fresh(), $this->admin))
        ->toThrow(RuntimeException::class)
        ->and(fn () => $this->service->request($case->fresh(), $this->officer, 'سند'))
        ->toThrow(RuntimeException::class);
});

it('leaves the family unfindable by the number that was destroyed', function () {
    $case = familyWithEverything($this->region);
    $nationalId = $case->national_id_encrypted;

    expect(app(DuplicateService::class)->existingFile($nationalId)?->getKey())->toBe($case->getKey());

    $this->service->request($case, $this->officer, 'سند قانوني');
    $this->service->execute($case->fresh(), $this->admin);

    // The hash is an HMAC of the identity number: keeping it would let anyone
    // with the key and a list of candidate numbers put the name back.
    expect(app(DuplicateService::class)->existingFile($nationalId))->toBeNull();
});

it('never shows an anonymised file to a donor as a person', function () {
    $case = familyWithEverything($this->region);

    $this->service->request($case, $this->officer, 'سند قانوني');
    $this->service->execute($case->fresh(), $this->admin);

    $payload = json_encode(
        (new \App\Http\Resources\MaskedCaseResource($case->fresh()))->resolve(),
        JSON_UNESCAPED_UNICODE,
    );

    expect($payload)->not->toContain('0911111111')->not->toContain('حي المنشية');
});

it('names only columns that exist, so a new personal column cannot be missed', function () {
    foreach (array_merge_recursive(
        AnonymiseService::CLEARED,
        AnonymiseService::MARKED,
        AnonymiseService::SCRAMBLED,
    ) as $class => $columns) {
        $model = new $class;
        $table = $model->getTable();

        foreach ($columns as $column) {
            expect(\Illuminate\Support\Facades\Schema::hasColumn($table, $column))
                ->toBeTrue($class.' names a column that does not exist: '.$column);
        }
    }
});
