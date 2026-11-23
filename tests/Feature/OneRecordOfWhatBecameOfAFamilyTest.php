<?php

use App\Filament\Resources\BeneficiaryResource\Pages\CreateBeneficiary;
use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Models\Delivery;
use App\Models\Donor;
use App\Models\Sponsorship;
use App\Services\FamilyTimeline;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | T-313 — phase two, item 2: «السجلّ الزمني الموحَّد للأسرة».
 |
 | Six tables held the life of a family file, each with its own screen. This
 | gathers them into one list, newest first, and reads only: it adds no table,
 | no column and no write.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('gathers the file, the study, the visit, the money and the kindness into one list', function () {
    $case = publishedCase($this->region);
    $delegate = userWithRole('delegate', ['region_id' => placeIn($this->region, 'village')->id]);
    $treasurer = userWithRole('treasurer');

    $case->visits()->create([
        'delegate_id' => $delegate->id,
        'client_uuid' => (string) Str::uuid(),
        'visited_at' => now()->subDays(20),
        'created_by' => $delegate->id,
    ]);

    DB::table('disbursements')->insert([
        'beneficiary_id' => $case->id,
        'amount' => 450000,
        'currency' => 'SYP',
        'period' => '2026-10-01',
        'status' => 'executed',
        'executed_at' => now()->subDays(10),
        'executed_by' => $treasurer->id,
        'created_by' => $treasurer->id,
        'created_at' => now()->subDays(10),
        'updated_at' => now()->subDays(10),
    ]);

    Sponsorship::create([
        'donor_id' => Donor::factory()->create()->id,
        'beneficiary_id' => $case->id,
        'amount' => 100000,
        'currency' => 'SYP',
        'start_date' => now()->subDays(5)->toDateString(),
        'end_date' => now()->addMonths(6)->toDateString(),
        'status' => 'active',
    ]);

    $entries = app(FamilyTimeline::class)->for($case->fresh());

    $kinds = array_column($entries, 'kind');

    expect($kinds)->toContain('file')
        ->and($kinds)->toContain('assessment')
        ->and($kinds)->toContain('visit')
        ->and($kinds)->toContain('money')
        ->and($kinds)->toContain('sponsorship');

    // Newest first, and no entry without a date.
    $dates = array_map(fn ($e) => $e['at']->getTimestamp(), $entries);
    $sorted = $dates;
    rsort($sorted);

    expect($dates)->toBe($sorted);

    // The money says how much, in which currency, for which month.
    $payment = collect($entries)->firstWhere('kind', 'money');

    expect($payment['title'])->toBe('نُفِّذ التحويل')
        ->and($payment['detail'])->toContain('450,000')
        ->and($payment['detail'])->toContain('2026-10')
        ->and($payment['actor'])->toBe($treasurer->name);
});

it('does not say a running sponsorship ended, on a date that has not come', function () {
    /*
     | Every sponsorship carries an end date — the model refuses one without
     | it — so reading the column alone wrote «انتهت كفالة» onto the record of
     | a sponsorship still paying, dated six months from now.
     */
    $case = publishedCase($this->region);

    Sponsorship::create([
        'donor_id' => Donor::factory()->create()->id,
        'beneficiary_id' => $case->id,
        'amount' => 100000,
        'currency' => 'SYP',
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => now()->addMonths(6)->toDateString(),
        'status' => 'active',
    ]);

    $titles = array_column(app(FamilyTimeline::class)->for($case), 'title');

    expect($titles)->toContain('بدأت كفالة')
        ->and($titles)->not->toContain('انتهت كفالة');

    // And once it really has ended, it is on the record.
    Sponsorship::query()->update(['end_date' => now()->subDay()->toDateString()]);

    expect(array_column(app(FamilyTimeline::class)->for($case), 'title'))
        ->toContain('انتهت كفالة');
});

it('puts a failed transfer on the record too, because it is why a month shows nothing', function () {
    $case = publishedCase($this->region);
    $treasurer = userWithRole('treasurer');

    DB::table('disbursements')->insert([
        'beneficiary_id' => $case->id,
        'amount' => 300000,
        'currency' => 'SYP',
        'period' => '2026-09-01',
        'status' => 'failed',
        'executed_at' => now()->subDays(30),
        'executed_by' => $treasurer->id,
        'created_by' => $treasurer->id,
        'created_at' => now()->subDays(30),
        'updated_at' => now()->subDays(30),
    ]);

    $entries = app(FamilyTimeline::class)->for($case);

    $failure = collect($entries)->firstWhere('kind', 'money_failed');

    expect($failure)->not->toBeNull()
        ->and($failure['title'])->toBe('تعذَّر التحويل');
});

it('records the family\'s own word on a transfer, the one step they take themselves', function () {
    $case = publishedCase($this->region);

    DB::table('disbursements')->insert([
        'beneficiary_id' => $case->id,
        'amount' => 200000, 'currency' => 'SYP', 'period' => '2026-11-01',
        'status' => 'executed',
        'executed_at' => now()->subDays(4),
        'beneficiary_responded_at' => now()->subDays(3),
        'dispute_reason_ar' => 'وصل المبلغ ناقصاً.',
        'created_at' => now()->subDays(4), 'updated_at' => now()->subDays(4),
    ]);

    $titles = array_column(app(FamilyTimeline::class)->for($case), 'title');

    expect($titles)->toContain('اعترضت الأسرة على الاستلام')
        ->and($titles)->not->toContain('أكّدت الأسرة الاستلام');
});

it('leaves an unnamed kind of delivery out rather than printing it in English', function () {
    $case = publishedCase($this->region);
    $officer = userWithRole('case_officer');

    Delivery::create([
        'beneficiary_id' => $case->id,
        'type' => 'in_kind',
        'proof_media_id' => null,
        'confirmed_by' => $officer->id,
        'confirmed_at' => now()->subDay(),
    ]);

    Delivery::create([
        'beneficiary_id' => $case->id,
        'type' => 'service',
        'proof_media_id' => null,
        'confirmed_by' => $officer->id,
        'confirmed_at' => now()->subDays(2),
    ]);

    $deliveries = collect(app(FamilyTimeline::class)->for($case))
        ->where('kind', 'delivery')
        ->values();

    expect($deliveries)->toHaveCount(2)
        ->and($deliveries[0]['detail'])->toBe('دعم عيني')
        ->and($deliveries[1]['detail'])->toBe('خدمة');
});

it('says so plainly when a file has nothing on it but its own opening', function () {
    $case = familyOf($this->region);

    $entries = app(FamilyTimeline::class)->for($case);

    expect(array_column($entries, 'kind'))->toBe(['file'])
        ->and($entries[0]['title'])->toBe('فُتِح الملفّ');
});

it('writes nothing, and adds no table of its own', function () {
    $case = publishedCase($this->region);

    $before = $case->toArray();
    $audit = DB::table('audit_log')->count();

    app(FamilyTimeline::class)->for($case);

    expect($case->fresh()->toArray())->toBe($before)
        ->and(DB::table('audit_log')->count())->toBe($audit);
});

it('draws it on the family screen, and not while a file is being opened', function () {
    $officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    passTwoFactor();
    $this->actingAs($officer);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $case = familyOf($this->region, adults: 1, children: 0);

    Livewire\Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->assertSee(__('sanabel.timeline.heading'))
        ->assertSee('فُتِح الملفّ');

    // On a file that does not exist yet the step has nothing to say, so it is
    // not there at all.
    Livewire\Livewire::test(CreateBeneficiary::class)
        ->assertDontSee(__('sanabel.timeline.heading'));
});
