<?php

use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\Media;
use App\Models\Setting;
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

    // T-71 — the two the association named on 12 October. One asks, the other
    // carries it out, and the rule that they cannot be the same person makes
    // them each other's check.
    $this->chairman = userWithRole('board_director', ['region_id' => $this->region->id]);
    $this->director = userWithRole('executive_director', ['region_id' => $this->region->id]);

    // These tests are about what is destroyed. When it may be destroyed is the
    // retention period, which has its own tests below, so it is out of the way
    // here.
    Setting::put('anonymise_after_years', 0);

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

    $this->service->request($case, $this->chairman, 'انتهت مدة الاحتفاظ النظامية وصدر التزام قانوني بالإتلاف');
    $this->service->execute($case->fresh(), $this->director);

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
})->group('FILE-04');

it('sweeps every record hanging off the file, not just the file', function () {
    $case = familyWithEverything($this->region);

    $this->service->request($case, $this->chairman, 'سند قانوني');
    $this->service->execute($case->fresh(), $this->director);

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
})->group('FILE-04');

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

    $this->service->request($case, $this->chairman, 'سند قانوني');
    $this->service->execute($case->fresh(), $this->director);

    // An identity card photograph is the identity.
    expect(Storage::disk('media_test')->exists($key))->toBeFalse()
        // The row is archived, not erased (rule 3), and serves nothing.
        ->and(Media::withTrashed()->whereKey($media->getKey())->exists())->toBeTrue()
        ->and(Media::withTrashed()->find($media->getKey())->isServable())->toBeFalse();
})->group('FILE-04');

it('closes their own portal account', function () {
    $case = familyWithEverything($this->region);
    $account = userWithRole('beneficiary', ['name' => 'محمد أحمد', 'email' => 'm@example.test']);
    $case->forceFill(['user_id' => $account->getKey()])->save();

    $this->service->request($case, $this->chairman, 'سند قانوني');
    $this->service->execute($case->fresh(), $this->director);

    $account->refresh();

    expect($account->name)->toBe('مطموس')
        ->and($account->email)->not->toContain('m@example.test')
        ->and($account->is_active)->toBeFalse();
})->group('FILE-04');

it('keeps the audit trail and takes the values out of it', function () {
    $case = familyWithEverything($this->region);

    // An edit to the name was logged with the name in it.
    $case->update(['family_name' => 'الحلبي']);

    $rowsBefore = AuditLog::where('entity_type', Beneficiary::class)
        ->where('entity_id', $case->getKey())->count();

    expect($rowsBefore)->toBeGreaterThan(0);

    $this->service->request($case, $this->chairman, 'سند قانوني');
    $this->service->execute($case->fresh(), $this->director);

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
})->group('OPS-02');

it('writes one audit row for the act, naming what went and never a value', function () {
    $case = familyWithEverything($this->region);

    $this->service->request($case, $this->chairman, 'انتهت مدة الاحتفاظ');
    $this->service->execute($case->fresh(), $this->director);

    $row = AuditLog::where('action', 'anonymised')
        ->where('entity_id', $case->getKey())->first();

    expect($row)->not->toBeNull()
        ->and($row->actor_id)->toBe($this->director->getKey())
        ->and($row->after_json['reason_ar'])->toBe('انتهت مدة الاحتفاظ')
        ->and($row->after_json['requested_by'])->toBe($this->chairman->getKey())
        ->and($row->after_json['cleared'])->toHaveKeys(['beneficiary', 'media', 'audit_rows'])
        // Column names and counts, not values.
        ->and(json_encode($row->after_json, JSON_UNESCAPED_UNICODE))->not->toContain('حي المنشية');
})->group('OPS-01');

it('needs two people, and refuses the one who asked', function () {
    $case = familyWithEverything($this->region);

    // Not asked for at all.
    expect(fn () => $this->service->execute($case, $this->director))->toThrow(RuntimeException::class);

    $this->service->request($case, $this->chairman, 'سند قانوني');

    // Asked for by the same person who would carry it out.
    expect(fn () => $this->service->execute($case->fresh(), $this->chairman))
        ->toThrow(RuntimeException::class);

    expect($case->fresh()->isAnonymised())->toBeFalse()
        ->and($case->fresh()->phone_encrypted)->toBe('0911111111');
})->group('FILE-04');

it('refuses a request with no legal basis behind it', function () {
    $case = familyWithEverything($this->region);

    expect(fn () => $this->service->request($case, $this->chairman, '   '))
        ->toThrow(RuntimeException::class);
})->group('FILE-04');

it('refuses to do it twice', function () {
    $case = familyWithEverything($this->region);

    $this->service->request($case, $this->chairman, 'سند قانوني');
    $this->service->execute($case->fresh(), $this->director);

    expect(fn () => $this->service->execute($case->fresh(), $this->director))
        ->toThrow(RuntimeException::class)
        ->and(fn () => $this->service->request($case->fresh(), $this->chairman, 'سند'))
        ->toThrow(RuntimeException::class);
})->group('FILE-04');

it('leaves the family unfindable by the number that was destroyed', function () {
    $case = familyWithEverything($this->region);
    $nationalId = $case->national_id_encrypted;

    expect(app(DuplicateService::class)->existingFile($nationalId)?->getKey())->toBe($case->getKey());

    $this->service->request($case, $this->chairman, 'سند قانوني');
    $this->service->execute($case->fresh(), $this->director);

    // The hash is an HMAC of the identity number: keeping it would let anyone
    // with the key and a list of candidate numbers put the name back.
    expect(app(DuplicateService::class)->existingFile($nationalId))->toBeNull();
})->group('FILE-04');

it('never shows an anonymised file to a donor as a person', function () {
    $case = familyWithEverything($this->region);

    $this->service->request($case, $this->chairman, 'سند قانوني');
    $this->service->execute($case->fresh(), $this->director);

    $payload = json_encode(
        (new \App\Http\Resources\MaskedCaseResource($case->fresh()))->resolve(),
        JSON_UNESCAPED_UNICODE,
    );

    expect($payload)->not->toContain('0911111111')->not->toContain('حي المنشية');
})->group('FILE-04');

it('names only columns that exist, so a new personal column cannot be missed', function () {
    foreach (array_merge_recursive(
        AnonymiseService::CLEARED,
        AnonymiseService::MARKED,
        AnonymiseService::SCRAMBLED,
        AnonymiseService::EMPTIED,
    ) as $class => $columns) {
        $model = new $class;
        $table = $model->getTable();

        foreach ($columns as $column) {
            expect(\Illuminate\Support\Facades\Schema::hasColumn($table, $column))
                ->toBeTrue($class.' names a column that does not exist: '.$column);
        }
    }
})->group('FILE-04');

/*
 | The gap these cover: the first version of the sweep cleared the file and its
 | immediate records, and left behind a published story naming the family with
 | their signed consent form attached, a public campaign carrying a wallet, a
 | public job profile, their medical report, the photograph of them receiving
 | aid, their complaint text, and the before-and-after values of every field it
 | had just erased. It read as an erasure and was not one.
 */

/**
 * A published story about a family. Post refuses to publish one without the
 * family's written consent on the record, so it is built the way the panel
 * builds it: draft, then the consent, then published.
 */
function storyAbout($case, $uploader): \App\Models\Post
{
    $post = \App\Models\Post::create([
        'slug' => 'story-'.$case->getKey(),
        'title_ar' => 'قصة اسرة ابو محمد',
        'excerpt_ar' => 'اسرة من حي المنشية',
        'body_ar' => 'محمد احمد وعائلته',
        'status' => 'draft',
        'beneficiary_id' => $case->getKey(),
    ]);

    $consent = app(MediaService::class)
        ->store(UploadedFile::fake()->image('consent.jpg'), $post, 'other', $uploader);

    $post->forceFill([
        'consent_signed_by_ar' => 'محمد احمد',
        'consent_signed_on' => now()->toDateString(),
        'consent_media_id' => $consent->getKey(),
        'status' => 'published',
    ])->save();

    return $post->refresh();
}

it('takes down the public content about the family, not only empties it', function () {
    $case = familyWithEverything($this->region);

    $post = storyAbout($case, $this->officer);

    $campaign = \App\Models\Campaign::create([
        'beneficiary_id' => $case->getKey(),
        'title_ar' => 'حملة لاسرة ابو محمد',
        'body_ar' => 'ساعدوا محمد احمد',
        'goal_amount' => 1_000_000,
        'currency' => config('sanabel.currency'),
        'wallet_encrypted' => '0922222222',
        // Campaign refuses to publish without it.
        'surplus_policy_text_ar' => 'الفائض يرحل للشهر التالي',
        'status' => 'published',
        'is_published' => true,
    ]);

    $profile = \App\Models\JobProfile::create([
        'beneficiary_id' => $case->getKey(),
        'trade_key' => 'smith',
        'summary_ar' => 'محمد احمد حداد بحي المنشية',
        'region_id' => $this->region->id,
        'status' => 'published',
    ]);

    $this->service->request($case, $this->chairman, 'سند قانوني');
    $this->service->execute($case->fresh(), $this->director);

    $post->refresh();
    $campaign->refresh();
    $profile->refresh();

    // Cleared.
    expect($post->title_ar)->toBe('مطموس')
        ->and($post->body_ar)->toBeNull()
        ->and($post->excerpt_ar)->toBeNull()
        ->and($post->consent_signed_by_ar)->toBeNull()
        ->and($campaign->title_ar)->toBe('مطموس')
        ->and($campaign->body_ar)->toBeNull()
        ->and($campaign->wallet_encrypted)->toBeNull()
        // summary_ar cannot be null on the table, so it takes the marker.
        ->and($profile->summary_ar)->toBe('مطموس');

    // And taken down: an emptied published story is still a published story,
    // and a campaign collecting for an erased file has nobody to pay.
    expect($post->is_published)->toBeFalse()
        ->and($post->status)->toBe('hidden')
        ->and($campaign->is_published)->toBeFalse()
        ->and($profile->status)->toBe('hidden');
})->group('FILE-04');

it('destroys every file belonging to the family, wherever it hangs', function () {
    $case = familyWithEverything($this->region);
    $service = app(MediaService::class);
    $disk = Storage::disk('media_test');

    // Hung off the family itself.
    $identity = $service->store(UploadedFile::fake()->image('identity.jpg'), $case, 'identity', $this->officer);

    // Hung off a health record: the medical report.
    $health = $case->healthRecords()->first();
    $report = $service->store(UploadedFile::fake()->image('report.jpg'), $health, 'medical', $this->officer);
    $health->forceFill(['evidence_media_id' => $report->getKey()])->save();

    // Hung off a post: the signed consent form.
    $post = storyAbout($case, $this->officer);
    $consent = Media::withTrashed()->find($post->consent_media_id);

    // Hung off a delivery: the photograph of them receiving the aid.
    // in_kind needs no transfer behind it (Delivery::WITHOUT_A_TRANSFER).
    $delivery = $case->deliveries()->create([
        'type' => 'in_kind',
        'note_ar' => 'استلمت من ابو محمد',
        'confirmed_at' => now(),
    ]);
    $proof = $service->store(UploadedFile::fake()->image('proof.jpg'), $delivery, 'other', $this->officer);
    $delivery->forceFill(['proof_media_id' => $proof->getKey()])->save();

    foreach ([$identity, $report, $consent, $proof] as $paper) {
        expect($disk->exists($paper->storage_key))->toBeTrue();
    }

    $this->service->request($case, $this->chairman, 'سند قانوني');
    $this->service->execute($case->fresh(), $this->director);

    foreach ([$identity, $report, $consent, $proof] as $paper) {
        expect($disk->exists($paper->storage_key))->toBeFalse(class_basename($paper).' survived')
            ->and(Media::withTrashed()->find($paper->getKey())->isServable())->toBeFalse();
    }

    expect($delivery->fresh()->note_ar)->toBeNull();
})->group('FILE-04');

it('takes the values out of the change requests that moved them', function () {
    $case = familyWithEverything($this->region);

    // A change request holds the before and after of the very field being
    // erased, so leaving it is the same leak as leaving the audit payload.
    app(\App\Services\CaseService::class)->requestChange(
        $case,
        $this->officer,
        ['phone_encrypted' => '0988777666'],
        'الاسرة بدلت رقمها',
    );

    $this->service->request($case->fresh(), $this->chairman, 'سند قانوني');
    $this->service->execute($case->fresh(), $this->director);

    $rows = DB::table('change_requests')->where('beneficiary_id', $case->getKey())->get();
    $everything = $rows->map(fn ($r) => (string) $r->payload_json.(string) $r->old_json.(string) $r->reason_ar)->implode(' ');

    expect($rows)->not->toBeEmpty()
        ->and($everything)->not->toContain('0988777666')
        ->and($everything)->not->toContain('0911111111');
})->group('FILE-04');

it('clears the complaint the family filed from their own account', function () {
    $case = familyWithEverything($this->region);
    $account = userWithRole('beneficiary', ['name' => 'محمد أحمد', 'email' => 'm2@example.test']);
    $case->forceFill(['user_id' => $account->getKey()])->save();

    $complaint = \App\Models\Complaint::create([
        'reference_no' => 'C-'.$case->getKey(),
        'submitted_by' => $account->getKey(),
        'subject_ar' => 'لم يصلني المبلغ',
        'body_ar' => 'انا محمد احمد من حي المنشية وما وصلني شي',
        'category' => 'payment',
        'status' => 'open',
    ]);

    $this->service->request($case->fresh(), $this->chairman, 'سند قانوني');
    $this->service->execute($case->fresh(), $this->director);

    $complaint->refresh();

    expect($complaint->body_ar)->toBeNull()
        // subject_ar cannot be null on the table, so it takes the marker.
        ->and($complaint->subject_ar)->toBe('مطموس')
        // The complaint itself survives as a record that one was filed.
        ->and($complaint->reference_no)->toBe('C-'.$case->getKey());
})->group('FILE-04');

it('leaves no trace of the family in any free text or file, anywhere', function () {
    // The catch-all. If a new table starts carrying words about a family and
    // nobody adds it to the sweep, this is what fails.
    $case = familyWithEverything($this->region);
    $case->deliveries()->create(['type' => 'in_kind', 'note_ar' => 'استلم ابو خالد بحي المنشية', 'confirmed_at' => now()]);
    storyAbout($case, $this->officer);

    $this->service->request($case, $this->chairman, 'سند قانوني');
    $this->service->execute($case->fresh(), $this->director);

    $needles = ['حي المنشية', '0911111111', '0922222222', 'OLD-1042', 'أبو فؤاد', 'د. سامر', '0944555666', '0955111222'];

    foreach ([
        'beneficiaries', 'household_members', 'housing', 'health_records', 'debts',
        'caregivers', 'assets', 'support_sources', 'visits', 'deliveries', 'disbursements',
        'change_requests', 'audit_log', 'posts', 'campaigns', 'job_profiles', 'complaints',
    ] as $table) {
        $dump = collect(DB::table($table)->get())->map(fn ($r) => json_encode($r, JSON_UNESCAPED_UNICODE))->implode(' ');

        foreach ($needles as $needle) {
            expect($dump)->not->toContain($needle, $table.' still carries '.$needle);
        }
    }
})->group('FILE-04');
